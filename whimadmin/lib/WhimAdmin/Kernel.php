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
use H42\WhimAdmin\Config\SettingsController;
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
    private string $coreI18nDir;
    private AssetBrowser $assetBrowser;
    private HistoryStore $contentHistory;
    private Recycler $contentRecycler;
    private RecyclerSweeper $recyclerSweeper;

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
        $i18nRel = (string)CoreConfig::get('paths.i18n', 'theme/i18n');
        $this->coreI18nDir = $i18nRel === '.' ? $this->coreRootDir : $this->coreRootDir . DIRECTORY_SEPARATOR . $i18nRel;
        $this->configWriter = new PhpArrayWriter($this->coreConfigDir);

        $this->assetBrowser = new AssetBrowser($this->coreRootDir . DIRECTORY_SEPARATOR . 'assets');

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
                username:      (string)($session['user'] ?? ''),
            )
            : null;

        $router->add('GET', '', fn(Request $r) => ($g = $authGuard($r)) ?? Response::redirect($r->url('pages')));

        $router->add('GET', 'pages', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->list($r));

        $router->add('GET',  'pages/new', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->newForm($r));
        $router->add('POST', 'pages/new', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->create($r));

        $router->add('GET',  'pages/edit', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->edit($r));
        $router->add('POST', 'pages/edit', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $pagesController->save($r));

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

        $settingsController = $session !== null && $session['stage'] === 'authed'
            ? new SettingsController(
                writer:        $this->configWriter,
                coreConfigDir: $this->coreConfigDir,
                i18nDir:       $this->coreI18nDir,
                csrf:          $csrf,
                views:         $this->renderer,
                audit:         $this->audit,
                username:      (string)($session['user'] ?? ''),
            )
            : null;

        $router->add('GET',  'settings/routes',    fn(Request $r) =>
            ($g = $authGuard($r)) ?? $settingsController->routesForm($r));
        $router->add('POST', 'settings/routes',    fn(Request $r) =>
            ($g = $authGuard($r)) ?? $settingsController->routesSave($r));
        $router->add('GET',  'settings/languages', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $settingsController->languagesForm($r));
        $router->add('POST', 'settings/languages', fn(Request $r) =>
            ($g = $authGuard($r)) ?? $settingsController->languagesSave($r));

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
