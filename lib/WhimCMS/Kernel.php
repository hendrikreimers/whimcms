<?php
declare(strict_types=1);

namespace H42\WhimCMS;

use H42\WhimCMS\Content\CacheSweeper as ContentCacheSweeper;
use H42\WhimCMS\Content\PageLoader;
use H42\WhimCMS\Frontend\ContactPostHandler;
use H42\WhimCMS\Frontend\LanguageDetector;
use H42\WhimCMS\Frontend\PageRenderer;
use H42\WhimCMS\Http\Responder;
use H42\WhimCMS\Path\PathResolver;
use H42\WhimCMS\Security\Http\RequestSecurity;
use H42\WhimCMS\Security\Secret;
use H42\WhimCMS\Template\Engine;

/**
 * Front-controller orchestrator.
 *
 * Two phases per process:
 *
 *   bootstrap()  Long-lived setup. Loads config, installs error
 *                handlers, wires the template engine + content
 *                pipeline + language detector. Runs once.
 *
 *   dispatch()   Per-request routing. Sanitises the request,
 *                resolves the base path, picks one of the routing
 *                branches:
 *                  - image-server endpoint
 *                  - SEO endpoints (robots.txt / sitemap.xml)
 *                  - root or bare-segment redirect to /<lang>/…
 *                  - 404
 *                  - legacy `.html` → canonical pretty URL (301)
 *                  - POST to home → ContactPostHandler
 *                  - happy path   → PageRenderer
 *
 * The Kernel does not render itself. It late-constructs a
 * PageRenderer (and a ContactPostHandler when a POST arrives) once
 * the base path is resolved, then delegates. That keeps the
 * request-bound state (`basePath`) out of the long-lived field set
 * and lets each renderer be tested in isolation.
 *
 * Anything thrown inside dispatch() is caught by the exception
 * handler installed during bootstrap.
 */
final class Kernel
{
    private bool $debug = false;
    private string $stateDir;
    private Engine $engine;
    private PageLoader $pageLoader;
    private LanguageDetector $languageDetector;
    /** @var list<string> */
    private array $allowedLayouts = ['default'];

    /** @var array<int, string> */
    private array $supportedLangs = ['en'];
    /** @var array<string, array<string, string>> */
    private array $routes = [];
    private bool $singleLang = true;

    /**
     * Filesystem paths resolved from `config/app.php → paths`. All four
     * positional keys are absolute paths under rootDir; `themeUrl` is
     * the URL fragment derived from the theme path (`""` when
     * paths.theme = '.', `"/theme"` when paths.theme = 'theme', etc.).
     *
     * @var array{theme:string, i18n:string, content:string, var:string, themeUrl:string}
     */
    private array $paths;

    public function __construct(private string $rootDir)
    {
    }

    /** Entry point. */
    public function run(): void
    {
        $this->bootstrap();
        $this->dispatch();
    }

    // ============================================================
    // Bootstrap
    // ============================================================

    private function bootstrap(): void
    {
        Config::loadDir($this->rootDir . '/config');
        Log::setLevel((string)Config::get('log_level', 'error'));

        $this->debug = (bool)Config::get('debug', false);
        $this->installErrorHandlers();

        // Resolve filesystem paths from config/app.php → paths via the
        // dedicated PathResolver: validates each value, builds absolute
        // paths under rootDir, ensures var/ exists with the WhimCMS
        // marker, realpath-contains everything. Then optionally route
        // logs to a project-local file when log_file is set.
        $resolver    = new PathResolver($this->rootDir);
        $this->paths = $resolver->resolve();
        $logFile     = $resolver->resolveOptionalLogFile($this->paths['var']);
        if ($logFile !== null) {
            Log::setFile($logFile);
        }

        $this->engine   = new Engine(
            $this->paths['theme'] . '/templates',
            $this->rootDir,
            $this->paths['var'],
        );
        $this->stateDir = $this->paths['var'] . '/state';
        I18n::setDir($this->paths['i18n']);

        $this->supportedLangs = (array)Config::get('supported_langs', ['en']);
        $this->routes         = (array)Config::get('routes', []);
        $this->singleLang     = count($this->supportedLangs) === 1;

        $this->languageDetector = new LanguageDetector(
            (bool)Config::get('detect_lang', true),
            (string)Config::get('default_lang', 'en'),
            $this->supportedLangs,
        );

        $this->bootstrapContent();
    }

    /**
     * Wire up the block-based content system: construct the PageLoader
     * against `content/` with the configured size + layout allowlists.
     * Pages without a matching .md file fall back to the legacy
     * `templates/pages/<slug>.html` flow, so this bootstrap is
     * additive — nothing is taken away from pages that have not yet
     * been migrated.
     *
     * Directives ({% blocks %}, {% html %}, etc.) self-register inside
     * the Engine via BuiltInDirectives; block-type schemas register
     * themselves via the `{@ block @}` annotation in each
     * partials/blocks/*.html, harvested by the Engine's boot-time
     * annotation scan. The Kernel does not touch either registry —
     * it just hands the populated BlockRegistry to the PageLoader
     * for parse-time validation.
     */
    private function bootstrapContent(): void
    {
        $contentCfg          = (array)Config::get('content', []);
        $maxBytes            = (int)($contentCfg['max_bytes'] ?? 262144);
        $allowed             = (array)($contentCfg['allowed_layouts'] ?? ['default']);
        $this->allowedLayouts = array_values(array_filter($allowed, 'is_string'));
        if ($this->allowedLayouts === []) {
            $this->allowedLayouts = ['default'];
        }

        // Application secret — used by the content-cache layer to sign
        // cache files (HMAC over JSON payload), so a planted file in
        // var/cache/content/ without the secret cannot pose as a valid
        // cache entry. Loaded lazily on first call; `Secret::load` is
        // idempotent so subsequent uses (CSRF, rate limiter, etc.) get
        // the cached value.
        $secret = Secret::load($this->stateDir);

        // Cache sweeper for var/cache/content. Sentinel-gated; runs at
        // most once per configured interval. Triggered from PageLoader
        // after a successful cache-write. Failure is non-fatal: any
        // sweep error is logged and never propagates to the render path.
        $contentCacheDir = $this->paths['var'] . '/cache/content';
        $contentSweeper = new ContentCacheSweeper(
            $contentCacheDir,
            $this->stateDir . '/.cache-sweep-content',
            (int)($contentCfg['cache_sweep_interval'] ?? 86400),
            $this->rootDir,
            $secret,
        );

        $this->pageLoader = new PageLoader(
            $this->paths['content'],
            $contentCacheDir,
            $this->engine->blocks(),
            $secret,
            $maxBytes,
            $this->allowedLayouts,
            $contentSweeper,
        );
    }

    private function installErrorHandlers(): void
    {
        (new ErrorHandler($this->debug))->install();
    }

    // ============================================================
    // Dispatch
    // ============================================================

    private function dispatch(): void
    {
        $rawUri     = (string)($_SERVER['REQUEST_URI']  ?? '/');
        $scriptName = (string)($_SERVER['SCRIPT_NAME']  ?? '/index.php');
        RequestSecurity::rejectUnsafeRequest($rawUri, $scriptName);

        $basePath = Router::detectBasePath($scriptName);
        $path     = Router::stripBase($rawUri, $basePath);

        // Cropped-image endpoint: served before normal page routing so
        // variants don't get caught by lang/slug resolution. URL pattern
        // `img-c/<filename>` — read-only, serves files the `{% image %}`
        // directive wrote earlier at render time. The `img-c` segment
        // does NOT use an underscore prefix because some shared hosts /
        // parent .htaccess setups refuse to serve `/_*`-prefixed URLs.
        if (str_starts_with($path, 'img-c/')) {
            $this->serveCroppedImage($path);
            return;
        }

        // SEO endpoints — language-agnostic, served at the deployment root.
        if ($path === 'robots.txt') {
            \H42\WhimCMS\Seo\Robots::send($basePath);
            return;
        }
        if ($path === 'sitemap.xml') {
            \H42\WhimCMS\Seo\Sitemap::send($basePath);
            return;
        }

        $resolved = Router::resolvePath($path, $this->supportedLangs, $this->routes);

        if ($this->maybeRedirectFromRoot($basePath, $path, $resolved)) {
            return;
        }
        if ($this->maybeRedirectBareSegment($basePath, $path, $resolved)) {
            return;
        }

        // Late-construct the page renderer with the resolved base path.
        // PageRenderer is per-request, but its long-lived dependencies
        // (engine, page loader, language detector) come from the
        // bootstrap-time fields.
        $pageRenderer = new PageRenderer(
            engine:           $this->engine,
            pageLoader:       $this->pageLoader,
            languageDetector: $this->languageDetector,
            basePath:         $basePath,
            supportedLangs:   $this->supportedLangs,
            routes:           $this->routes,
            singleLang:       $this->singleLang,
            allowedLayouts:   $this->allowedLayouts,
            stateDir:         $this->stateDir,
            themeUrl:         $this->paths['themeUrl'],
            debug:            $this->debug,
        );

        if ($resolved === null) {
            $pageRenderer->renderNotFound($path);
            return;
        }
        if ($resolved['legacyHtml']) {
            Responder::redirect(
                Router::canonicalUrl(
                    $resolved['slug'],
                    $resolved['lang'],
                    $this->routes[$resolved['lang']] ?? [],
                    $basePath,
                    $this->singleLang
                ),
                301
            );
            return;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST' && $resolved['slug'] === 'home') {
            $contactPostHandler = new ContactPostHandler(
                engine:         $this->engine,
                pageRenderer:   $pageRenderer,
                basePath:       $basePath,
                supportedLangs: $this->supportedLangs,
                routes:         $this->routes,
                singleLang:     $this->singleLang,
                stateDir:       $this->stateDir,
                themeUrl:       $this->paths['themeUrl'],
            );
            $contactPostHandler->handle($resolved);
            return;
        }

        $pageRenderer->render($resolved);
    }

    /** @param array<string, mixed>|null $resolved */
    private function maybeRedirectFromRoot(string $basePath, string $path, ?array $resolved): bool
    {
        if ($resolved !== null || $this->singleLang || $path !== '') {
            return false;
        }
        $target = $this->languageDetector->detect();
        Responder::redirect($basePath . '/' . $target . '/', 302);
        return true;
    }

    /** @param array<string, mixed>|null $resolved */
    private function maybeRedirectBareSegment(string $basePath, string $path, ?array $resolved): bool
    {
        if ($resolved !== null || $this->singleLang || $path === '') {
            return false;
        }
        $target = $this->languageDetector->detect();
        $candidate = Router::resolvePath($target . '/' . $path, $this->supportedLangs, $this->routes);
        if ($candidate === null) {
            return false;
        }
        Responder::redirect(
            Router::canonicalUrl(
                $candidate['slug'],
                $candidate['lang'],
                $this->routes[$candidate['lang']] ?? [],
                $basePath,
                false
            ),
            301
        );
        return true;
    }

    /**
     * Hand off to the cropped-image endpoint. Read-only: serves files
     * the `{% image %}` directive wrote during a previous template
     * render. URL pattern: `/img-c/<basename>-<hash>.<ext>`.
     */
    private function serveCroppedImage(string $path): void
    {
        \H42\WhimCMS\Image\CroppedServer::fromConfig($this->paths['var'])->handle($path);
    }
}
