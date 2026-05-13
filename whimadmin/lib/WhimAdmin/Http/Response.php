<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Http;

/**
 * Outbound HTTP response.
 *
 * Plain value object built by controllers, sent by the Kernel via
 * Response::send(). Keeps response composition (status, headers,
 * body) separate from emission so a controller is testable without
 * needing to mock header()/echo().
 *
 * Headers carrying user-controlled data are NOT supported here —
 * Cookie::header() and Location use only safe values, and any
 * future user-facing header would need explicit CR/LF rejection.
 */
final class Response
{
    private int $status;
    private string $body;
    /** @var array<string, string> */
    private array $headers;

    /** @var list<string> raw Set-Cookie header values, kept in order */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        int $status = 200,
        string $body = '',
        array $headers = [],
    ) {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        // Hard-strip CR/LF defensively even though our callers control
        // the location. Any internal bug introducing newlines would
        // otherwise become a header-injection vector.
        $clean = preg_replace('/[\r\n\x00]/', '', $location) ?? $location;
        return new self($status, '', [
            'Location'     => $clean,
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    public static function plain(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * JSON response. Encoded with JSON_THROW_ON_ERROR so a non-encodable
     * value (e.g. a closure leaking into the payload) fails loud at the
     * controller, not silently in the browser. Marked `Cache-Control:
     * no-store` because every JSON endpoint here serves authed data.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        try {
            $body = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            // Encoding failure → 500 with a non-leaky plain body.
            // Caller's data shape is the bug.
            return new self(
                500,
                'Internal error',
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }
        return new self($status, $body, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = preg_replace('/[\r\n\x00]/', '', $value) ?? $value;
        return $clone;
    }

    /**
     * Append a `Set-Cookie` header. The cookie is built with secure
     * defaults (HttpOnly, SameSite=Strict, Path=basePath, Secure when
     * the request was over HTTPS).
     */
    public function withCookie(
        string $name,
        string $value,
        int $maxAgeSeconds,
        string $basePath = '/',
        bool $secure = true,
    ): self {
        // Whitelist the cookie name shape — token-shape characters only.
        if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Bad cookie name: {$name}");
        }
        $value  = rawurlencode($value);
        $maxAge = max(0, $maxAgeSeconds);
        $expires = gmdate('D, d-M-Y H:i:s', time() + $maxAge) . ' GMT';
        $path = $basePath === '' ? '/' : rtrim($basePath, '/') . '/';
        $parts = [
            "{$name}={$value}",
            "Path={$path}",
            "Expires={$expires}",
            "Max-Age={$maxAge}",
            'HttpOnly',
            'SameSite=Strict',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }
        $clone = clone $this;
        $clone->cookies[] = implode('; ', $parts);
        return $clone;
    }

    public function withClearedCookie(string $name, string $basePath = '/', bool $secure = true): self
    {
        return $this->withCookie($name, '', 0, $basePath, $secure);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $rawCookie) {
                header('Set-Cookie: ' . $rawCookie, false);
            }
        }
        echo $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }
}
