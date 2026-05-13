<?php
declare(strict_types=1);

namespace H42\WhimAdmin;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Auth\FirstRunController;
use H42\WhimAdmin\Auth\LoginController;
use H42\WhimAdmin\Auth\LogoutController;
use H42\WhimAdmin\Auth\OtpController;
use H42\WhimAdmin\Auth\OtpMailer;
use H42\WhimAdmin\Auth\OtpStore;
use H42\WhimAdmin\Auth\Session;
use H42\WhimAdmin\Auth\SetupController;
use H42\WhimAdmin\Auth\SetupTokenStore;
use H42\WhimAdmin\Assets\AssetBrowser;
use H42\WhimAdmin\Assets\AssetsController;
use H42\WhimAdmin\Auth\UserStore;
use H42\WhimAdmin\Config\PhpArrayWriter;
use H42\WhimAdmin\Content\BlockSchemaLoader;
use H42\WhimAdmin\Content\ClipboardStore;
use H42\WhimAdmin\Content\FormDecoder;
use H42\WhimAdmin\Content\FormRenderer;
use H42\WhimAdmin\Content\HistoryStore;
use H42\WhimAdmin\Content\IconLibrary;
use H42\WhimAdmin\Content\PageRepository;
use H42\WhimAdmin\Content\PagesController;
use H42\WhimAdmin\Content\Recycler;
use H42\WhimAdmin\Http\CookieJar;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\Http\Router;
use H42\WhimAdmin\Maintenance\RecyclerSweeper;
use H42\WhimAdmin\Pages\OverlayWriter;
use H42\WhimAdmin\Pages\PageMetaFormDecoder;
use H42\WhimAdmin\Pages\PageTypeSchemaLoader;
use H42\WhimAdmin\Pages\PagesTreeController;
use H42\WhimAdmin\Pages\PagesTreeMutationController;
use H42\WhimAdmin\Pages\RoutesUpdater;
use H42\WhimAdmin\Pages\Tree\TreeAggregator;
use H42\WhimAdmin\Pages\Tree\TreeMutator;
use H42\WhimAdmin\Pages\Tree\UrlDeriver;
use H42\WhimAdmin\Path\PathResolver;
use H42\WhimAdmin\View\Renderer;
use H42\WhimCMS\Config as CoreConfig;
use H42\WhimCMS\Mail\PhpMailTransport;
use H42\WhimCMS\Security\RateLimiter;
use H42\WhimCMS\Security\Secret;

/**
 * WhimAdmin front-controller orchestrator.
 *
 * Two phases per process, mirroring the core's lifecycle:
 *
 *   bootstrap()  Long-lived setup. Loads config (admin + core),
 *                resolves paths, ensures whimadmin/var/ exists with
 *                our marker, installs error handlers, wires every
 *                long-lived service this request might need.
 *
 *   dispatch()   Per-request routing. Splits two paths:
 *                - first-run (no user yet)  → FirstRunController
 *                - normal operation         → routed via Router
 *
 * Anything thrown inside dispatch() bubbles to the ErrorHandler
 * installed during bootstrap.
 */
final class Kernel
{
    private string $rootDir;
    private string $coreRootDir;

    private bool $debug = false;

    /** @var array{root:string, var:string, state:string, logs:string, views:string, config:string} */
    private array $paths;

    private string $secret;
    private Renderer $renderer;
    private AuditLog $audit;
    private RateLimiter $rateLimiter;
    private UserStore $users;
    private SetupTokenStore $setupTokens;
    private OtpStore $otps;
    private Session $sessions;
    private OtpMailer $otpMailer;

    private PageRepository $pageRepo;
    private BlockSchemaLoader $blockSchemas;
    private FormRenderer $formRenderer;
    private ClipboardStore $clipboard;
    private PhpArrayWriter $configWriter;
    private string $coreConfigDir;
    private AssetBrowser $assetBrowser;
    private HistoryStore $contentHistory;
    private Recycler $contentRecycler;
    private RecyclerSweeper $recyclerSweeper;
    private PageTypeSchemaLoader $pageTypes;
    private TreeAggregator $treeAggregator;
    private OverlayWriter $overlayWriter;
    private RoutesUpdater $routesUpdater;
    private TreeMutator $treeMutator;
    private PageMetaFormDecoder $pageMetaDecoder;
    private string $treeRoot;
    /** @var list<string> */
    private array $treeOverlayAllowedSections;

    public function __construct(string $rootDir, string $coreRootDir)
    {
        $this->rootDir     = $rootDir;
        $this->coreRootDir = $coreRootDir;
    }

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
        // Validate ranges right after load — surfaces operator typos
        // (e.g. otp.ttl_seconds = 0) as a clear 500 at boot, before
        // any controller path consumes the broken value.
        Config::validate();
        // Core config is required for mail.from / mail.enabled. Loading
        // is idempotent so calling it from whimadmin doesn't conflict
        // with a future scenario where the core has already loaded.
        CoreConfig::loadDir($this->coreRootDir . '/config');

        $this->debug = (bool)Config::get('debug', false);

        (new ErrorHandler($this->debug))->install();

        $this->paths = (new PathResolver($this->rootDir))->resolve();

        // Whimadmin owns its own application secret — separate from
        // the core's so a hypothetical compromise of one doesn't cross
        // into the other. Same primitive (random_bytes + lockfile).
        $this->secret = Secret::load($this->paths['state']);

        $this->renderer = new Renderer($this->paths['views']);
        $this->audit    = new AuditLog($this->paths['logs'], $this->secret);

        $rl = (array)Config::get('rate_limit', []);
        $this->rateLimiter = new RateLimiter(
            stateDir:      $this->paths['state'],
            secret:        $this->secret,
            windowSeconds: (int)($rl['window_seconds'] ?? 300),
            maxPerWindow:  (int)($rl['max_attempts']   ?? 5),
        );

        $this->users       = new UserStore($this->paths['state']);
        $this->setupTokens = new SetupTokenStore(
            stateDir:    $this->paths['state'],
            secret:      $this->secret,
            ttlSeconds:  (int)Config::get('setup.token_ttl_seconds', 86400),
        );
        $this->otps = new OtpStore($this->paths['state'], $this->secret);

        $sess = (array)Config::get('session', []);
        $this->sessions = new Session(
            stateDir:        $this->paths['state'],
            secret:          $this->secret,
            idleSeconds:     (int)($sess['idle_seconds']     ?? 1800),
            absoluteSeconds: (int)($sess['absolute_seconds'] ?? 28800),
            bindStrategy:    (string)($sess['bind_strategy'] ?? 'ip_ua'),
        );

        $this->otpMailer = new OtpMailer(
            renderer:  $this->renderer,
            transport: new PhpMailTransport(),
            audit:     $this->audit,
            stateDir:  $this->paths['state'],
            dailyMax:  (int)Config::get('otp.daily_max', 50),
        );

        $this->bootstrapContent();
    }

    /**
     * Wire up the content-editing services. These read from the WhimCMS
     * core's content/ + theme/ directories (via core Config) and write
     * back through atomic-rename + history snapshots.
     */
    private function bootstrapContent(): void
    {
        $contentRel = (string)CoreConfig::get('paths.content', 'content');
        $themeRel   = (string)CoreConfig::get('paths.theme',   'theme');

        $contentAbs = $this->coreRootDir . DIRECTORY_SEPARATOR . $contentRel;
        $themeAbs   = $themeRel === '.' ? $this->coreRootDir : $this->coreRootDir . DIRECTORY_SEPARATOR . $themeRel;

        $partialsDir = $themeAbs . '/templates/partials/blocks';
        $glyphPath   = $themeAbs . '/templates/partials/icons/glyph.html';
        $sidecarDir  = $this->rootDir . '/config/blocks';
        $fieldsDir   = $this->paths['views'] . '/fields';

        $contentCfg = (array)Config::get('content', []);
        $historyMax = (int)($contentCfg['history_max'] ?? 10);
        $maxBytes   = (int)CoreConfig::get('content.max_bytes', 262144);

        $this->contentHistory  = new HistoryStore($contentAbs, $historyMax);
        $this->contentRecycler = new Recycler($contentAbs);
        $this->pageRepo = new PageRepository(
            contentDir: $contentAbs,
            history:    $this->contentHistory,
            recycler:   $this->contentRecycler,
            maxBytes:   $maxBytes,
        );

        $this->blockSchemas = new BlockSchemaLoader(
            partialsDir: $partialsDir,
            sidecarDir:  $sidecarDir,
            fieldsDir:   $fieldsDir,
        );

        $this->formRenderer = new FormRenderer(
            views: $this->renderer,
            icons: new IconLibrary($glyphPath),
        );

        $this->clipboard = new ClipboardStore($this->paths['state'], $this->secret);

        $this->coreConfigDir = $this->coreRootDir . DIRECTORY_SEPARATOR . 'config';
        $this->configWriter = new PhpArrayWriter($this->coreConfigDir);

        $this->assetBrowser = new AssetBrowser($this->coreRootDir . DIRECTORY_SEPARATOR . 'assets');

        $this->pageTypes = new PageTypeSchemaLoader(
            configDir: $this->rootDir . '/config/page-types',
        );

        // Tree-driver config — read-only consumed from the core's
        // config/i18n.php. The theme/operator owns these knobs;
        // WhimAdmin reacts to whatever values are present, with
        // defaults that match the bundled showcase if a key is
        // missing so a half-configured install still boots.
        $treeRoot     = (string)CoreConfig::get('i18n_overlay.page_tree.root', 'navigation');
        $treeSections = (array)CoreConfig::get('i18n_overlay.page_tree.sections', ['main', 'footer']);
        $supportedLangs = (array)CoreConfig::get('supported_langs', ['en']);
        $defaultLang    = (string)CoreConfig::get('default_lang', 'en');

        // Filter the operator-supplied lists down to safe string values
        // — the aggregator already re-checks per item, but a defective
        // config (non-string entries) should never reach a filesystem
        // call site.
        $supportedLangs = array_values(array_filter($supportedLangs, 'is_string'));
        $treeSections   = array_values(array_filter($treeSections,   'is_string'));

        $this->treeAggregator = new TreeAggregator(
            supportedLangs:    $supportedLangs,
            defaultLang:       $defaultLang,
            treeRoot:          $treeRoot,
            configuredSections: $treeSections,
            overlayDir:        $contentAbs,
            routesPath:        $this->coreRootDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'routes.php',
            contentDir:        $contentAbs,
        );

        // Write-side services for tree mutations. The overlay writer
        // is path-bound to the same content dir the aggregator reads
        // from; the routes updater shares the PhpArrayWriter, the
        // single whitelisted writer to `config/routes.php`.
        $contentRealDir = realpath($contentAbs);
        if ($contentRealDir === false) {
            throw new \RuntimeException("Content directory not resolvable for overlay writer: {$contentAbs}");
        }
        $this->overlayWriter = new OverlayWriter(
            overlayDir:     $contentAbs,
            contentRealDir: $contentRealDir,
        );
        $allowedOverlaySections = (array)CoreConfig::get('i18n_overlay.allowed_sections', ['navigation']);
        $allowedOverlaySections = array_values(array_filter($allowedOverlaySections, 'is_string'));
        $this->treeRoot = $treeRoot;
        $this->treeOverlayAllowedSections = $allowedOverlaySections;

        $this->routesUpdater = new RoutesUpdater(
            writer:     $this->configWriter,
            routesPath: $this->coreRootDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'routes.php',
        );

        // URL derivation helper — extracted from TreeMutator for the
        // refactor; injects RoutesUpdater so deriveParentSegment + the
        // cascade can read + mutate routes under the same flock + log
        // discipline as the rest of the tree mutation pipeline.
        $treeUrlDeriver = new UrlDeriver(
            routes:   $this->routesUpdater,
            treeRoot: $treeRoot,
        );

        $this->treeMutator = new TreeMutator(
            overlayWriter:           $this->overlayWriter,
            routes:                  $this->routesUpdater,
            pages:                   $this->pageRepo,
            recycler:                $this->contentRecycler,
            urls:                    $treeUrlDeriver,
            contentDir:              $contentAbs,
            contentRealDir:          $contentRealDir,
            treeRoot:                $treeRoot,
            allowedOverlaySections:  $allowedOverlaySections,
            configuredSections:      $treeSections,
            aggregator:              $this->treeAggregator,
            stateDir:                $this->paths['state'],
        );
        // Layout cross-check: the decoder rejects layout values that
        // aren't in the core's allowed_layouts allowlist before the
        // save reaches the .md writer. Without it, the editor would
        // silently accept any kebab-case layout name and the public
        // site would render with the default fallback — confusing
        // and hard to debug.
        $allowedLayouts = (array)CoreConfig::get('content.allowed_layouts', ['default']);
        $allowedLayouts = array_values(array_filter($allowedLayouts, 'is_string'));
        $this->pageMetaDecoder = new PageMetaFormDecoder($allowedLayouts);

        $rec = (array)Config::get('recycler', []);
        $this->recyclerSweeper = new RecyclerSweeper(
            stateDir:           $this->paths['state'],
            contentRecycler:    $this->contentRecycler,
            history:            $this->contentHistory,
            assetBrowser:       $this->assetBrowser,
            audit:              $this->audit,
            intervalSeconds:    (int)($rec['sweep_interval_seconds'] ?? 86400),
            contentMaxAgeDays:  (int)($rec['content_max_age_days']   ?? 30),
            assetsMaxAgeDays:   (int)($rec['assets_max_age_days']    ?? 30),
        );
    }

    // ============================================================
    // Dispatch
    // ============================================================

    private function dispatch(): void
    {
        $req = Request::fromGlobals();

        if (!$this->users->exists()) {
            $this->dispatchFirstRun($req)->send();
            return;
        }

        // User exists → setup is complete. Idempotent cleanup of any
        // setup-token residue (HMAC record + plaintext sidecar) that
        // a previous setup may have left behind if a crash occurred
        // between UserStore::create() and SetupTokenStore::consume().
        // is_file() check is cheap; a real-world hit is rare.
        if ($this->setupTokens->isPresent()) {
            $this->setupTokens->consume();
        }

        $cookies     = CookieJar::fromRequest($req);
        $csrf        = new Csrf($this->secret, $req->clientIp(), $req->userAgent());
        $cookieName  = (string)Config::get('session.cookie_name', 'whimadmin_sid');

        $cookieValue = $cookies->read($cookieName);
        $session     = $cookieValue === ''
            ? null
            : $this->sessions->load($cookieValue, $req->clientIp(), $req->userAgent());

        // Auto-sweep for the page-recycler / page-history /
        // asset-recycler trees fires here, gated on an active authed
        // session so unauthenticated traffic can't trigger filesystem
        // work. The sweeper itself is sentinel-gated: it skips fast
        // on requests that aren't due (configured interval not
        // elapsed yet), so this hook is one filemtime call in the
        // hot path.
        if ($session !== null && ($session['stage'] ?? '') === 'authed') {
            $this->recyclerSweeper->sweepIfDue();
        }

        $router  = $this->buildRouter($req, $cookies, $csrf, $cookieName, $cookieValue, $session);
        $handler = $router->match($req->method(), $req->path());

        if ($handler === null) {
            (Response::plain('Not found.', 404))->send();
            return;
        }

        /** @var Response $response */
        $response = $handler($req);
        $response->send();
    }

    /**
     * Preserve `?lang=` and `?slug=` across the legacy → canonical
     * URL redirects so a bookmarked /pages/edit?lang=en&slug=imprint
     * lands on /pages/blocks?lang=en&slug=imprint, not the bare
     * canonical path. Other query params are dropped on purpose —
     * we don't promise stability for them.
     */
    private function preserveLangSlug(Request $req): string
    {
        $parts = [];
        $lang = $req->query('lang', '');
        $slug = $req->query('slug', '');
        if ($lang !== null && $lang !== '') $parts[] = 'lang=' . urlencode($lang);
        if ($slug !== null && $slug !== '') $parts[] = 'slug=' . urlencode($slug);
        return $parts === [] ? '' : ('?' . implode('&', $parts));
    }

    private function dispatchFirstRun(Request $req): Response
    {
        $csrf = new Csrf($this->secret, $req->clientIp(), $req->userAgent());
        $setup = new SetupController(
            users:    $this->users,
            tokens:   $this->setupTokens,
            csrf:     $csrf,
            renderer: $this->renderer,
            audit:    $this->audit,
        );
        $firstRun = new FirstRunController(
            tokens:           $this->setupTokens,
            setup:            $setup,
            renderer:         $this->renderer,
            audit:            $this->audit,
            tokenTtlSeconds:  (int)Config::get('setup.token_ttl_seconds', 86400),
        );
        return $firstRun->dispatch($req);
    }

    /**
     * @param array{user:string, stage:string, issued:int, last:int, bind_key:string, csrf_seed:string}|null $session
     */
    private function buildRouter(
        Request $req,
        CookieJar $cookies,
        Csrf $csrf,
        string $cookieName,
        string $cookieValue,
        ?array $session,
    ): Router {
        $router = new Router();

        $otpCfg  = (array)Config::get('otp', []);
        $sessCfg = (array)Config::get('session', []);

        $login = new LoginController(
            users:        $this->users,
            otps:         $this->otps,
            otpMailer:    $this->otpMailer,
            sessions:     $this->sessions,
            rateLimiter:  $this->rateLimiter,
            csrf:         $csrf,
            renderer:     $this->renderer,
            audit:        $this->audit,
            cookies:      $cookies,
            cookieName:   $cookieName,
            otpConfig:    [
                'ttl_seconds'  => (int)($otpCfg['ttl_seconds']  ?? 300),
                'digits'       => (int)($otpCfg['digits']       ?? 6),
                'max_attempts' => (int)($otpCfg['max_attempts'] ?? 5),
            ],
        );

        $otpController = new OtpController(
            otps:                 $this->otps,
            sessions:             $this->sessions,
            rateLimiter:          $this->rateLimiter,
            csrf:                 $csrf,
            renderer:             $this->renderer,
            audit:                $this->audit,
            cookies:              $cookies,
            cookieName:           $cookieName,
            authedSessionMaxAge:  (int)($sessCfg['absolute_seconds'] ?? 28800),
        );

        $logout = new LogoutController(
            sessions:    $this->sessions,
            csrf:        $csrf,
            audit:       $this->audit,
            cookies:     $cookies,
            cookieName:  $cookieName,
        );

        // Authed-only routes share this guard. Returns null when the
        // request can proceed; otherwise a Response that the caller
        // sends back instead of invoking the controller.
        $authGuard = function (Request $r) use ($session): ?Response {
            if ($session === null) {
                return Response::redirect($r->url('login'));
            }
            if ($session['stage'] !== 'authed') {
                return Response::redirect($r->url('otp'));
            }
            return null;
        };

        // ----- Public (only when no session) -----
        $router->add('GET',  'login', fn(Request $r) => $session === null
            ? $login->showForm($r)
            : Response::redirect($r->url('')));
        $router->add('POST', 'login', fn(Request $r) => $session === null
            ? $login->submit($r)
            : Response::redirect($r->url('')));

        // ----- Pre-OTP (validates stage internally) -----
        $router->add('GET',  'otp', fn(Request $r) => $otpController->showForm($r, $cookieValue));
        $router->add('POST', 'otp', fn(Request $r) => $otpController->submit($r,    $cookieValue));

        // ----- Authed -----
        $router->add('POST', 'logout', function (Request $r) use ($logout, $cookieValue, $session) {
            if ($session === null || $session['stage'] !== 'authed') {
                return Response::redirect($r->url('login'));
            }
            return $logout->submit($r, $cookieValue, (string)($session['user'] ?? null));
        });

        $pagesController = $session !== null && $session['stage'] === 'authed'
            ? new PagesController(
                pages:         $this->pageRepo,
                schemas:       $this->blockSchemas,
                formRenderer:  $this->formRenderer,
                formDecoder:   new FormDecoder($this->blockSchemas->all()),
                clipboard:     $this->clipboard,
                assetBrowser:  $this->assetBrowser,
                recycler:      $this->contentRecycler,
                history:       $this->contentHistory,
                csrf:          $csrf,
                views:         $this->renderer,
                audit:         $this->audit,
                routes:        $this->routesUpdater,
                username:      (string)($session['user'] ?? ''),
            )
            : null;

        $router->add('GET', '', fn(Request $r) => ($g = $authGuard($r)) ?? Response::redirect($r->url('pages')));

        // /pages = the tree-view (canonical pages URL after Phase 4a
        // cleanup). The block editor moved to /pages/blocks; the
        // legacy flat list at /pages and the /pages/new form are gone.
        $router->add('GET',  'pages/blocks', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->edit($r));
        $router->add('POST', 'pages/blocks', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->save($r));

        // Back-compat: bookmarks / cached form-actions hitting the
        // old paths land softly on the canonical equivalents.
        $router->add('GET',  'pages/tree-view', fn(Request $r) =>
            ($g = $authGuard($r)) ?? Response::redirect($r->url('pages') . $this->preserveLangSlug($r)));
        $router->add('GET',  'pages/edit',      fn(Request $r) =>
            ($g = $authGuard($r)) ?? Response::redirect($r->url('pages/blocks') . $this->preserveLangSlug($r)));
        $router->add('POST', 'pages/edit',      fn(Request $r) =>
            ($g = $authGuard($r)) ?? Response::redirect($r->url('pages/blocks') . $this->preserveLangSlug($r)));

        // Page recycler
        $router->add('GET',  'pages/recycler',         fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->recyclerView($r));
        $router->add('POST', 'pages/recycler/restore', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->recyclerRestore($r));
        $router->add('POST', 'pages/recycler/purge',   fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->recyclerPurge($r));

        // Page history
        $router->add('GET',  'pages/history',         fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->historyView($r));
        $router->add('GET',  'pages/history/raw',     fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->historyRaw($r));
        $router->add('POST', 'pages/history/restore', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->historyRestore($r));

        // /settings/routes and /settings/languages removed in
        // Phase 5: route + language management is now exclusively
        // surfaced through the tree-view editor (per-page URL field +
        // overlay sections). Languages stay operator-domain
        // (config/i18n.php is SFTP-edited). Old bookmarks 404.

        // ----- Page tree (read-only JSON) -----
        // Phase 1 of the split-view editor rebuild. Coexists with the
        // legacy /pages/* form-based editor — both work simultaneously
        // until the UI in Phase 3 cuts over.
        $pagesTreeController = $session !== null && $session['stage'] === 'authed'
            ? new PagesTreeController(
                aggregator: $this->treeAggregator,
                pageTypes:  $this->pageTypes,
                pages:      $this->pageRepo,
                csrf:       $csrf,
                audit:      $this->audit,
                views:      $this->renderer,
                username:   (string)($session['user'] ?? ''),
            )
            : null;

        $router->add('GET', 'pages', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeController->index($r));
        $router->add('GET', 'pages/tree', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeController->tree($r));
        $router->add('GET', 'pages/tree/types', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeController->types($r));
        $router->add('GET', 'pages/tree/node', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeController->node($r));

        // ----- Page tree mutations (JSON POST, CSRF-guarded) -----
        // Each operation is one POST endpoint. Both `X-CSRF-Token`
        // header (preferred) and `_csrf` in the JSON body are
        // accepted. The controller's `FORM_ID = 'tree'` is shared
        // across all mutation endpoints so the editor's UI re-uses a
        // single token across DnD interactions.
        $pagesTreeMutationController = $session !== null && $session['stage'] === 'authed'
            ? new PagesTreeMutationController(
                mutator:    $this->treeMutator,
                pageTypes:  $this->pageTypes,
                decoder:    $this->pageMetaDecoder,
                aggregator: $this->treeAggregator,
                csrf:       $csrf,
                audit:      $this->audit,
                username:   (string)($session['user'] ?? ''),
                debug:      $this->debug,
            )
            : null;

        $router->add('POST', 'pages/tree/create', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeMutationController->create($r));
        $router->add('POST', 'pages/tree/move',   fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeMutationController->move($r));
        $router->add('POST', 'pages/tree/rename', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeMutationController->rename($r));
        $router->add('POST', 'pages/tree/retype', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeMutationController->retype($r));
        $router->add('POST', 'pages/tree/delete', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeMutationController->delete($r));
        $router->add('POST', 'pages/tree/save',   fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesTreeMutationController->save($r));

        $assetsController = $session !== null && $session['stage'] === 'authed'
            ? new AssetsController(
                browser:  $this->assetBrowser,
                csrf:     $csrf,
                views:    $this->renderer,
                audit:    $this->audit,
                username: (string)($session['user'] ?? ''),
            )
            : null;

        $router->add('GET',  'assets',                  fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->browse($r));
        $router->add('POST', 'assets/upload',           fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->upload($r));
        $router->add('POST', 'assets/mkdir',            fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->mkdir($r));
        $router->add('POST', 'assets/rename',           fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->rename($r));
        $router->add('POST', 'assets/delete',           fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->delete($r));
        $router->add('GET',  'assets/recycler',         fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->recyclerView($r));
        $router->add('POST', 'assets/recycler/purge',   fn(Request $r) => ($g = $authGuard($r)) ?? $assetsController->recyclerPurge($r));

        return $router;
    }
}
