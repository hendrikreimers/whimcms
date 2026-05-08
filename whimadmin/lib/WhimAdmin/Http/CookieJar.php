<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Http;

/**
 * Centralised cookie policy for WhimAdmin.
 *
 * Holds the per-deployment knobs (cookie name prefix, base path, and
 * whether the request is over HTTPS) and exposes a tiny API:
 *
 *   $jar->set($name, $value, $maxAge);
 *   $jar->clear($name);
 *
 * Every cookie set through this class carries the same secure
 * defaults — HttpOnly, SameSite=Strict, Secure (when HTTPS), Path
 * scoped to whimadmin's basePath. There is no way to ask the jar
 * for a cookie WITHOUT those flags, so a future controller bug
 * cannot accidentally weaken the cookie surface.
 *
 * The jar mutates a `Response` rather than emitting cookies directly,
 * keeping response composition test-friendly: a controller hands the
 * jar a Response and gets back a Response with the new Set-Cookie
 * header attached.
 *
 * Reading is also centralised — `$jar->read($req, $name)` returns
 * the raw cookie string (from $_COOKIE via the Request) or '' if
 * absent.
 */
final class CookieJar
{
    public function __construct(
        private string $basePath,
        private bool $secure,
    ) {
    }

    /**
     * Convenience constructor: derive `secure` from the request,
     * `basePath` from the request, and return a per-request jar.
     */
    public static function fromRequest(Request $req): self
    {
        return new self(
            basePath: $req->basePath(),
            secure:   $req->isHttps(),
        );
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function attach(Response $response, string $name, string $value, int $maxAgeSeconds): Response
    {
        return $response->withCookie(
            name:           $name,
            value:          $value,
            maxAgeSeconds:  $maxAgeSeconds,
            basePath:       $this->basePath,
            secure:         $this->secure,
        );
    }

    public function clear(Response $response, string $name): Response
    {
        return $response->withClearedCookie($name, $this->basePath, $this->secure);
    }

    /**
     * Read a cookie value from PHP's `$_COOKIE`. Returns '' if absent
     * or non-string. The Request object is used as a typed gateway —
     * not strictly required, but keeps superglobal access centralised.
     */
    public function read(string $name): string
    {
        $raw = $_COOKIE[$name] ?? '';
        return is_string($raw) ? $raw : '';
    }
}
