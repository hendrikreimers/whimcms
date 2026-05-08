<?php
declare(strict_types=1);

namespace H42\WhimCMS\Http;

use H42\WhimCMS\Log;

/**
 * Tiny HTTP-response helper. Centralised so the front controller doesn't
 * sprinkle header()/echo all over and so the JSON shape is consistent.
 */
final class Responder
{
    /** Send a JSON body with a status code. Output buffer must still be empty. */
    public static function json(int $status, mixed $payload): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 303 See Other after a successful POST so refresh can't resubmit.
     *
     * Only same-origin server-rooted paths are accepted. PHP's `header()`
     * already rejects CRLF since 5.1.2, but this guard is defence in depth:
     * if a future code path ever lets user input flow into a redirect URL,
     * Open-Redirect attacks (`?next=//evil.tld/`) and protocol-relative
     * smuggling are blocked at this single chokepoint instead of every
     * caller having to remember.
     *
     * Accepted shape: starts with `/`, but NOT `//` (scheme-relative) and
     * NOT `/\` (Windows-style scheme-relative). No control characters.
     * Anything else is logged and the client gets a 400 instead of being
     * sent to an unintended destination.
     */
    public static function redirect(string $url, int $status = 303): void
    {
        if (!self::isSafeRedirectTarget($url)) {
            Log::warn('Responder: rejected unsafe redirect target', ['url_prefix' => substr($url, 0, 64)]);
            self::plain(400, '400 — Bad Request');
            return;
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $url);
        }
    }

    /**
     * True iff $url is a same-origin server-rooted path. See redirect()
     * for rationale. Public so other callers can pre-validate before
     * passing into redirect() if they need a boolean instead of a 400.
     */
    public static function isSafeRedirectTarget(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (strpbrk($url, "\r\n\0") !== false) {
            return false;
        }
        if ($url[0] !== '/') {
            return false;
        }
        if (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\')) {
            return false;
        }
        return true;
    }

    /** Plain-text status response (used for 400/404/500 pre-render). */
    public static function plain(int $status, string $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $body;
    }

    /**
     * Translate a ContactController result into a JSON shape suitable
     * for fetch() consumers. Same information that drives a re-render
     * is exposed structured so client-side JS can patch the DOM.
     *
     * @param array<string, mixed> $result
     */
    public static function contactJson(array $result): void
    {
        switch ($result['action'] ?? '') {
            case 'redirect':
            case 'silent_ok':
                self::json(200, ['ok' => true, 'redirect' => $result['url'] ?? null]);
                return;
            case 'rerender':
                self::json(422, [
                    'ok'           => false,
                    'errors'       => $result['errors'] ?? [],
                    'global_error' => $result['global_error'] ?? null,
                ]);
                return;
            default:
                self::json(500, ['ok' => false, 'error' => 'unexpected']);
        }
    }
}
