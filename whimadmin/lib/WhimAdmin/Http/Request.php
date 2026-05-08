<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Http;

use H42\WhimCMS\Security\Http\RequestSecurity;

/**
 * Read-only view of the current HTTP request.
 *
 * Built once per request by the Kernel. Encapsulates safe access to
 * REQUEST_URI, method, base path, query, body, headers, IP, and UA —
 * all values are sanitised at the boundary so controllers downstream
 * can trust their inputs.
 *
 * The request URI is rejected via core's RequestSecurity if it
 * contains NUL/CR/LF (header-injection class), before any path
 * routine touches it.
 */
final class Request
{
    /** @var array<int|string, mixed> nested string-tree from $_GET */
    private array $get;

    /** @var array<int|string, mixed> nested string-tree from $_POST */
    private array $post;

    private string $method;
    private string $path;          // path AFTER the basePath, leading slash stripped
    private string $basePath;      // detected base path (no trailing slash)
    private string $rawUri;
    private string $clientIp;
    private string $userAgent;

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public function __construct(
        string $method,
        string $rawUri,
        string $basePath,
        string $path,
        array $get,
        array $post,
        string $clientIp,
        string $userAgent,
    ) {
        $this->method    = strtoupper($method);
        $this->rawUri    = $rawUri;
        $this->basePath  = $basePath;
        $this->path      = $path;
        $this->get       = $get;
        $this->post      = $post;
        $this->clientIp  = $clientIp;
        $this->userAgent = $userAgent;
    }

    /**
     * Build from PHP superglobals. Call once at request entry.
     *
     * Detects the base path by stripping the script directory from the
     * raw URI. So with a request to `/whimadmin/login` and SCRIPT_NAME
     * `/whimadmin/index.php`, the base path is `/whimadmin` and the
     * routed path is `login`.
     */
    public static function fromGlobals(): self
    {
        $rawUri     = (string)($_SERVER['REQUEST_URI']  ?? '/');
        $scriptName = (string)($_SERVER['SCRIPT_NAME']  ?? '/index.php');
        RequestSecurity::rejectUnsafeRequest($rawUri, $scriptName);

        $basePath = self::detectBasePath($scriptName);
        $path     = self::stripBase($rawUri, $basePath);

        // Strip query string from path
        $qsAt = strpos($path, '?');
        if ($qsAt !== false) {
            $path = substr($path, 0, $qsAt);
        }
        $path = ltrim($path, '/');

        return new self(
            method:    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rawUri:    $rawUri,
            basePath:  $basePath,
            path:      $path,
            get:       self::sanitiseTree($_GET ?? []),
            post:      self::sanitiseTree($_POST ?? []),
            clientIp:  self::detectClientIp(),
            userAgent: self::headerString('HTTP_USER_AGENT'),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function rawUri(): string
    {
        return $this->rawUri;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Heuristic HTTPS detection. Reads PHP's `$_SERVER['HTTPS']` and
     * falls back to a port-443 check. Behind a TLS-terminating reverse
     * proxy that doesn't set `HTTPS=on` (Cloudflare, AWS ALB, …), this
     * returns false — the operator should configure php-fpm or a
     * fastcgi rule to populate `HTTPS=on` from the proxy header, or
     * accept that the `Secure` cookie flag will be omitted (cookies
     * still travel inside the proxy's TLS to the visitor; only the
     * proxy↔origin hop is plain HTTP).
     */
    public function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $port = (string)($_SERVER['SERVER_PORT'] ?? '');
        return $port === '443';
    }

    /**
     * Single-value scalar accessor for query params. Returns the
     * default when the key is absent OR when the value is a nested
     * array (caller asked for a string but the form sent a tree).
     */
    public function query(string $name, ?string $default = null): ?string
    {
        $v = $this->get[$name] ?? null;
        return is_string($v) ? $v : $default;
    }

    /**
     * Single-value scalar accessor for POST fields. Same semantics as
     * query() — returns the default for absent keys or non-string
     * values.
     */
    public function post(string $name, ?string $default = null): ?string
    {
        $v = $this->post[$name] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @return array<int|string, mixed> full $_POST tree (sanitised) */
    public function postAll(): array
    {
        return $this->post;
    }

    /** Build a base-path-prefixed URL for redirects/links. */
    public function url(string $relPath = ''): string
    {
        $relPath = ltrim($relPath, '/');
        $base = $this->basePath === '' ? '' : $this->basePath;
        return $base . '/' . $relPath;
    }

    /**
     * URL prefix for the public site relative to the admin's base
     * path. With whimadmin at `/whimadmin`, this returns `''` (so
     * `siteUrl('/assets/x.jpg')` yields `/assets/x.jpg`). With
     * whimadmin at `/sub/whimadmin`, returns `/sub`. Used to build
     * preview links to the public site's static asset URLs.
     */
    public function siteRoot(): string
    {
        if ($this->basePath === '') return '';
        $parent = dirname($this->basePath);
        if ($parent === '/' || $parent === '\\' || $parent === '.') return '';
        return str_replace('\\', '/', $parent);
    }

    private static function detectBasePath(string $scriptName): string
    {
        // SCRIPT_NAME like '/whimadmin/index.php' → base '/whimadmin'.
        // For root-mounted ('/index.php') the base is ''.
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        return $dir === '' || $dir === '/' ? '' : $dir;
    }

    private static function stripBase(string $rawUri, string $basePath): string
    {
        // Drop query string.
        $qsAt = strpos($rawUri, '?');
        $pathOnly = $qsAt === false ? $rawUri : substr($rawUri, 0, $qsAt);
        if ($basePath !== '' && str_starts_with($pathOnly, $basePath)) {
            return substr($pathOnly, strlen($basePath));
        }
        return $pathOnly;
    }

    private static function detectClientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    private static function headerString(string $key): string
    {
        $raw = (string)($_SERVER[$key] ?? '');
        // Strip any control bytes — defence in depth around UA in
        // logs and signature material.
        return preg_replace('/[\x00-\x1F\x7F]/', '', $raw) ?? '';
    }

    /**
     * Recursively sanitise a $_GET / $_POST tree.
     *
     * Allowed shape: associative tree where every leaf is a string and
     * every branch is an array. Anything else (objects, ints as values,
     * resources) is dropped. Numeric string keys remain numeric strings
     * — PHP coerces those when used as nested-array indices.
     *
     * Depth is capped to 16 to bound the work an attacker can force
     * with a deeply-nested form payload.
     *
     * @param array<int|string, mixed> $arr
     * @return array<int|string, mixed>
     */
    private static function sanitiseTree(array $arr, int $depth = 0): array
    {
        if ($depth > 16) {
            return [];
        }
        $out = [];
        foreach ($arr as $k => $v) {
            if (!is_string($k) && !is_int($k)) continue;
            if (is_string($v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = self::sanitiseTree($v, $depth + 1);
            }
            // Other types (object, int leaf, …) silently dropped.
        }
        return $out;
    }
}
