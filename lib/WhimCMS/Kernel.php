<?php
declare(strict_types=1);

namespace H42\WhimCMS;

use H42\WhimCMS\Content\AttributeParser;
use H42\WhimCMS\Content\CacheSweeper as ContentCacheSweeper;
use H42\WhimCMS\Content\PageLoader;
use H42\WhimCMS\Frontend\ContactPostHandler;
use H42\WhimCMS\Frontend\LanguageDetector;
use H42\WhimCMS\Frontend\PageRenderer;
use H42\WhimCMS\Http\Responder;
use H42\WhimCMS\Image\CroppedCacheSweeper;
use H42\WhimCMS\Image\CroppedServer;
use H42\WhimCMS\Maintenance\Coordinator;
use H42\WhimCMS\Maintenance\DayDirSweeper;
use H42\WhimCMS\Maintenance\TtlFileSweeper;
use H42\WhimCMS\Path\PathResolver;
use H42\WhimCMS\Security\Http\RequestSecurity;
use H42\WhimCMS\Security\Secret;
use H42\WhimCMS\Seo\LlmsTxt;
use H42\WhimCMS\Seo\Robots;
use H42\WhimCMS\Seo\Sitemap;
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
        // Editor-managed i18n overlay lives under content/ and is
        // wired here so I18n::load() can pick up the file for the
        // active language. Passing the validated content path keeps
        // the loader from synthesising paths from raw config; the
        // PathResolver has already realpath-contained it.
        I18n::setOverlayDir($this->paths['content']);

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

        // Apply operator-configurable AttributeParser caps. Floored
        // at sensible minimums inside setLimits() so a typo here
        // can't break parsing. Called once at boot — every
        // AttributeParser::parse() afterwards sees these limits.
        $apCfg = (array)($contentCfg['attribute_parser'] ?? []);
        AttributeParser::setLimits(
            (int)($apCfg['max_lines']     ?? AttributeParser::MAX_LINES),
            (int)($apCfg['max_key_len']   ?? AttributeParser::MAX_KEY_LEN),
            (int)($apCfg['max_value_len'] ?? AttributeParser::MAX_VALUE_LEN),
        );

        // Application secret — used by the content-cache layer to sign
        // cache files (HMAC over JSON payload), so a planted file in
        // var/cache/content/ without the secret cannot pose as a valid
        // cache entry. Loaded lazily on first call; `Secret::load` is
        // idempotent so subsequent uses (CSRF, rate limiter, etc.) get
        // the cached value.
        $secret = Secret::load($this->stateDir);

        // Cache sweeper for var/cache/content. Sentinel-gated; runs at
        // most once per configured interval, triggered end-of-request
        // by the Maintenance\Coordinator below (the old PageLoader
        // cache-write trigger never fired on a warm cache). Failure is
        // non-fatal: logged, never propagates to the render path.
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
        );

        $this->bootstrapMaintenance($contentSweeper);
    }

    /**
     * Wire the maintenance Coordinator: every retention sweeper of this
     * kernel, triggered once per request via a shutdown hook (covers
     * the exit paths a call at the end of dispatch() would miss; under
     * FPM the response is handed off first, so visitors never wait).
     *
     * Retention values are DERIVED from the existing config windows —
     * no second source of truth, no new config keys. The mtime
     * argument that makes each TTL safe: the writers rewrite their
     * file on every hit, so once `now − mtime > window` every entry
     * inside is provably expired. The margin absorbs clock skew.
     *
     * `photoshare/var/` is deliberately NOT on this list and must not
     * be added: it holds consent evidence whose retention is a legal
     * question, not a hygiene question.
     */
    private function bootstrapMaintenance(ContentCacheSweeper $contentSweeper): void
    {
        // ORDER GUARD: this must stay AFTER installErrorHandlers().
        // Shutdown handlers run in registration order — the
        // ErrorHandler's fatal handler has to emit its 500 page BEFORE
        // the Coordinator hook calls fastcgi_finish_request(), or a
        // fatal request would be flushed to the client with no body.
        //
        // Hourly consideration for the state-file sweepers: the
        // deletion cap is per RUN, so the drain rate is cap × runs/day
        // per store (500 × 24 = 12 000/day). A daily interval would
        // cap drainage at 500/day — under a distributed flood with
        // >500 fresh /64 sources a day the store would grow faster
        // than it shrinks (review finding W0-A3). Cost is unchanged:
        // one filemtime check per sweeper per request.
        $interval = 3600;
        // The mail-log day-dir sweeper keeps a daily pace — it deletes
        // at most a handful of directories, one per calendar day.
        $dayDirInterval = 86400;

        $imgCfg = (array)Config::get('images', []);
        $croppedSweeper = new CroppedCacheSweeper(
            $this->paths['var'] . '/cache/img-cropped',
            $this->stateDir . '/.cache-sweep-img-cropped',
            (int)($imgCfg['cropped_cache_sweep_interval'] ?? 86400),
            $this->rootDir,
            (int)($imgCfg['cropped_cache_max_age'] ?? 30 * 86400),
        );

        // Margin added onto each functional window before a state file
        // may be deleted. One hour absorbs any realistic clock skew.
        $margin = 3600;

        $rateWindow  = (int)Config::get('rate_limit.window_seconds', 600);
        $missWindow  = (int)Config::get('captcha.miss_window', 1800);
        $captchaAge  = (int)Config::get('captcha.max_age', 7200);
        // Day-keyed counter files: yesterday's file is never read again;
        // 8 days keeps a week of operational visibility. Constant on
        // purpose — not worth a config key.
        $counterTtl  = 8 * 86400;
        $mailLogDays = (int)Config::get('mail.log_retention_days', 30);
        // blocklist.json prunes itself — but only inside strike().
        // isBlocked() prunes read-only (Blocklist::readPruned), so once
        // a burst stops the file keeps its high-water mark until some
        // later strike rewrites it; on a quiet site that is months, and
        // every contact POST re-reads and re-decodes entries that are
        // by then all expired. The TTL argument needs one term more
        // than the stores above: a block entry stores
        // `now + block_duration`, a timestamp in the FUTURE relative to
        // mtime, so the TTL has to clear max(fail_window,
        // block_duration) — the window alone is not enough. Both
        // fallbacks mirror ContactController::fromConfig, which in turn
        // mirrors the shipped config/security.php (1800 / 900). Note the
        // max() makes the block_duration fallback inert: fail_window
        // already dominates at 1800. It is kept aligned anyway so the
        // next reader does not take a divergence for intent.
        $blockTtl    = max(
            (int)Config::get('blocklist.fail_window', 1800),
            (int)Config::get('blocklist.block_duration', 900),
        );

        $keyHash = '/^[a-f0-9]{32}\.json$/';

        $coordinator = new Coordinator(
            $contentSweeper,
            $croppedSweeper,
            new TtlFileSweeper(
                $this->stateDir . '/ratelimit',
                $this->stateDir . '/.sweep-ratelimit',
                $interval,
                $this->rootDir,
                $keyHash,
                $rateWindow + $margin,
            ),
            new TtlFileSweeper(
                $this->stateDir . '/captcha-miss',
                $this->stateDir . '/.sweep-captcha-miss',
                $interval,
                $this->rootDir,
                $keyHash,
                $missWindow + $margin,
            ),
            new TtlFileSweeper(
                $this->stateDir . '/captcha-used',
                $this->stateDir . '/.sweep-captcha-used',
                $interval,
                $this->rootDir,
                '/^[a-f0-9]{32}$/',
                $captchaAge + $margin,
            ),
            new TtlFileSweeper(
                $this->stateDir . '/mail-counter',
                $this->stateDir . '/.sweep-mail-counter',
                $interval,
                $this->rootDir,
                '/^\d{4}-\d{2}-\d{2}\.txt$/',
                $counterTtl,
            ),
            // Unlike every sweeper above, this one is pointed at
            // var/state ITSELF — blocklist.json is a single file, not a
            // store directory. What keeps that safe is the allowlist
            // regex alone: `secret`, `.secret.lock` and all sentinels
            // live in this directory too. Never loosen the pattern —
            // deleting `secret` would rotate every CSRF token, every
            // throttle bucket and the honeypot field name in one go.
            new TtlFileSweeper(
                $this->stateDir,
                $this->stateDir . '/.sweep-blocklist',
                $interval,
                $this->rootDir,
                '/^blocklist\.json$/',
                $blockTtl + $margin,
            ),
            new DayDirSweeper(
                $this->stateDir . '/mail-log',
                $this->stateDir . '/.sweep-mail-log',
                $dayDirInterval,
                $this->rootDir,
                $mailLogDays,
            ),
        );
        $coordinator->registerShutdownHook();
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
            Robots::send($basePath, $this->paths['content']);
            return;
        }
        // sitemap.xml — gated on seo.indexable for the same reason as
        // llms.txt below: a non-indexable site must not advertise its
        // page list. Without this gate the endpoint was the odd one out
        // in its own family — robots.txt answers a bare `Disallow: /`
        // and never reaches its `Sitemap:` line (Seo\Robots), llms.txt
        // 404s, and sitemap.xml happily published every URL anyway.
        // Falls through to normal routing when disabled, which resolves
        // to a 404 (no supported language matches "sitemap.xml", and no
        // route is registered for it). No dangling reference is created:
        // the non-indexable robots.txt does not point at the sitemap.
        if ($path === 'sitemap.xml' && (bool)Config::get('seo.indexable', false)) {
            Sitemap::send($basePath, $this->pageLoader, $this->singleLang);
            return;
        }
        // llms.txt (llmstxt.org) — opt-in plain-text page index for LLM
        // crawlers. Served only when seo.llms.enabled AND seo.indexable
        // are both true; otherwise this falls through to normal routing
        // (→ 404), so a non-indexable / disabled site never advertises
        // its page list. Multi-lang: /<lang>/llms.txt; /llms.txt maps to
        // the default language.
        if ((bool)Config::get('seo.llms.enabled', false)
            && (bool)Config::get('seo.indexable', false)) {
            $llmsLang = $this->matchLlmsTxtLang($path);
            if ($llmsLang !== null) {
                LlmsTxt::send($basePath, $this->pageLoader, $this->singleLang, $llmsLang);
                return;
            }
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
        // Pages whose POST is routed to the contact pipeline. Config-
        // driven so the contact/booking form can live on a dedicated
        // page (or several) instead of only home. Default ['home']
        // preserves the historical behaviour exactly. Every pipeline
        // gate (CSRF / captcha / honeypot / rate-limit / contact.enabled)
        // is unchanged and still applies per request.
        $contactPostSlugs = (array)Config::get('contact.post_slugs', ['home']);
        if ($method === 'POST' && in_array($resolved['slug'], $contactPostSlugs, true)) {
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
            302
        );
        return true;
    }

    /**
     * Match the llms.txt endpoint and return the language to render it
     * in, or null when $path is not an llms.txt request.
     *
     *   single-lang:  "llms.txt"        → the only supported language
     *   multi-lang:   "llms.txt"        → default language
     *                 "<lang>/llms.txt" → that language (must be supported)
     *
     * Pure path inspection — it does not consult the route table, so it
     * can sit ahead of Router::resolvePath() without shadowing normal
     * page resolution (no route maps a ".txt" segment).
     */
    private function matchLlmsTxtLang(string $path): ?string
    {
        if ($this->singleLang) {
            return $path === 'llms.txt' ? ($this->supportedLangs[0] ?? null) : null;
        }
        if ($path === 'llms.txt') {
            return (string)Config::get('default_lang', $this->supportedLangs[0] ?? 'en');
        }
        if (str_ends_with($path, '/llms.txt')) {
            $lang = substr($path, 0, -strlen('/llms.txt'));
            if ($lang !== '' && in_array($lang, $this->supportedLangs, true)) {
                return $lang;
            }
        }
        return null;
    }

    /**
     * Hand off to the cropped-image endpoint. Read-only: serves files
     * the `{% image %}` directive wrote during a previous template
     * render. URL pattern: `/img-c/<basename>-<hash>.<ext>`.
     */
    private function serveCroppedImage(string $path): void
    {
        CroppedServer::fromConfig($this->paths['var'])->handle($path);
    }
}
