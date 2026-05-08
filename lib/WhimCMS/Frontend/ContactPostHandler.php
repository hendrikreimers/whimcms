<?php
declare(strict_types=1);

namespace H42\WhimCMS\Frontend;

use H42\WhimCMS\Config;
use H42\WhimCMS\Http\Responder;
use H42\WhimCMS\I18n;
use H42\WhimCMS\Log;
use H42\WhimCMS\Router;
use H42\WhimCMS\Security\Http\RequestSecurity;
use H42\WhimCMS\Template\Engine;

/**
 * HTTP-glue layer between the dispatcher and the contact-form pipeline
 * (`H42\WhimCMS\Frontend\ContactController`).
 *
 * Responsibilities:
 *   1. Detect content-type (form-encoded vs. JSON).
 *   2. Read the body with explicit byte limits — `Content-Length` is
 *      a client hint, not authoritative. JSON path measures the actual
 *      `php://input`; form path relies on PHP's `post_max_size` plus
 *      a fast-path header check.
 *   3. Honour the `contact.enabled` master switch (drops POSTs at the
 *      door without running validator / CSRF / captcha).
 *   4. Build the controller, hand off `$post`, and translate its
 *      result back to an HTTP response:
 *        - JSON request → `Responder::contactJson()`
 *        - Success      → 303 redirect to `?sent=1#contact` (PRG)
 *        - Validation   → re-render the page through `PageRenderer`
 *                         with `formState` so the contact block
 *                         repopulates errors and field values.
 *
 * Lazy mailer-context factory: the controller invokes the closure
 * passed in here only after every gate (block, CSRF, rate-limit,
 * honeypot, captcha, field validation) has passed; bot submissions
 * that fail any of those steps never pay for context construction.
 */
final class ContactPostHandler
{
    /**
     * Defensive ceiling on the inbound POST body. PHP's `post_max_size`
     * already enforces a global limit, but explicit app-level rejection
     * lets us short-circuit obviously-too-big payloads before any of
     * the form-handling code runs.
     *
     * For JSON requests we measure the actual body bytes from
     * `php://input` because the `Content-Length` header is client-
     * supplied and trivially spoofable — a request with
     * `Content-Length: 0` and a 60 KB body would otherwise slip past
     * the header check. For form-encoded bodies, PHP has already
     * populated `$_POST` by the time we run, so the header check
     * there is a fast-path hint backed by `post_max_size` from
     * php.ini.
     */
    private const MAX_POST_BYTES = 65536; // 64 KB

    /**
     * @param list<string>                          $supportedLangs
     * @param array<string, array<string, string>>  $routes
     */
    public function __construct(
        private Engine $engine,
        private PageRenderer $pageRenderer,
        private string $basePath,
        private array $supportedLangs,
        private array $routes,
        private bool $singleLang,
        private string $stateDir,
        private string $themeUrl,
    ) {
    }

    /**
     * Run the contact-form POST pipeline for a request whose route
     * resolved to the home page.
     *
     * @param array<string, mixed> $resolved {lang, slug, …} from Router
     */
    public function handle(array $resolved): void
    {
        $isJson = $this->wantsJson();

        // Master switch (config/contact.php → contact.enabled). When the
        // site runs without a contact form, drop direct POSTs at the door
        // — no validator, no CSRF check, no captcha strike, no log entry.
        // mail.enabled remains the inner safety net for staging.
        if (!(bool)Config::get('contact.enabled', true)) {
            if ($isJson) {
                Responder::json(404, ['ok' => false, 'error' => 'not_found']);
            } else {
                $this->pageRenderer->renderNotFound((string)($_SERVER['REQUEST_URI'] ?? '/'));
            }
            return;
        }

        $post = $this->readPostBody($isJson);
        if ($post === null) {
            // Read failed (size cap or malformed JSON). The reader has
            // already emitted the appropriate response.
            return;
        }

        $controller = ContactController::fromConfig(
            $this->engine,
            Config::all(),
            $this->stateDir
        );

        $clientIp   = RequestSecurity::clientIp();
        $bindKey    = RequestSecurity::clientBindKey();
        $langRoutes = $this->routes[$resolved['lang']] ?? [];
        $homeUrl    = Router::canonicalUrl('home', $resolved['lang'], $langRoutes, $this->basePath, $this->singleLang);
        $successUrl = $homeUrl . '?sent=1#contact';

        $lang = $resolved['lang'];
        $ctxFactory = function () use ($lang, $bindKey): array {
            $dict = I18n::load($lang, $this->basePath, $this->singleLang);
            return RenderContext::build(
                dict:           $dict,
                lang:           $lang,
                slug:           'home',
                basePath:       $this->basePath,
                meta:           $dict['meta']['home'] ?? ['title' => '', 'description' => ''],
                pageTemplate:   'pages/home',
                supportedLangs: $this->supportedLangs,
                routes:         $this->routes,
                singleLang:     $this->singleLang,
                stateDir:       $this->stateDir,
                csrfBindKey:    $bindKey,
                themeUrl:       $this->themeUrl,
            );
        };

        $result = $controller->handle((array)$post, $ctxFactory, $clientIp, $bindKey, $successUrl);

        if ($isJson) {
            Responder::contactJson($result);
            return;
        }
        if (in_array($result['action'] ?? '', ['redirect', 'silent_ok'], true)) {
            Responder::redirect($result['url'] ?? $successUrl, 303);
            return;
        }
        // Re-render with errors + values populated.
        $this->pageRenderer->render($resolved, $result, false);
    }

    /**
     * Read and size-check the request body, returning the parsed array
     * (form-encoded or JSON). Returns null on any read failure after
     * having already emitted the appropriate error response, so the
     * caller just bails out.
     *
     * @return array<int|string, mixed>|null
     */
    private function readPostBody(bool $isJson): ?array
    {
        if ($isJson) {
            // Read the raw body once; measure its actual length, not the
            // client-supplied Content-Length header.
            $raw = file_get_contents('php://input');
            if (!is_string($raw)) {
                $raw = '';
            }
            if (strlen($raw) > self::MAX_POST_BYTES) {
                Log::info('Contact: body too large', ['bytes' => strlen($raw)]);
                Responder::json(413, ['ok' => false, 'error' => 'too_large']);
                return null;
            }
            $parsed = $this->parseJsonBody($raw);
            if ($parsed === null) {
                Responder::json(400, ['ok' => false, 'error' => 'invalid_json']);
                return null;
            }
            return $parsed;
        }

        // Form-encoded: $_POST is already populated by PHP under
        // post_max_size. Header check is best-effort fast-path.
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_POST_BYTES) {
            Log::info('Contact: body too large', ['bytes' => $contentLength]);
            Responder::plain(413, '413 — Payload Too Large');
            return null;
        }
        return $_POST;
    }

    private function wantsJson(): bool
    {
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $accept      = (string)($_SERVER['HTTP_ACCEPT']  ?? '');
        return str_contains($contentType, 'application/json')
            || str_contains($accept, 'application/json');
    }

    /**
     * Parse a JSON request body that has already been size-checked by
     * the caller. Returns `[]` for an empty body (treated as no fields),
     * `null` for malformed JSON.
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonBody(string $raw): ?array
    {
        if ($raw === '') {
            return [];
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : [];
    }
}
