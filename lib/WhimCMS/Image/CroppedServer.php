<?php
declare(strict_types=1);

namespace H42\WhimCMS\Image;

/**
 * Endpoint handler for `/img-c/<filename>` requests.
 *
 * Serves a cropped/resized image variant produced earlier by the
 * `{% image %}` template directive. The variants live in
 * `var/cache/img-cropped/` (deny-all from the web), and this handler
 * is the only path that exposes them.
 *
 * Read-only contract:
 *   - The handler NEVER writes a cache file. Generation is the
 *     directive's job, at template-render time.
 *   - An attacker probing `/img-c/anything-...` gets 404 unless the
 *     file already exists from a legit render. So the URL space is
 *     bounded by what real templates actually request — there's no
 *     way to fan-out cache writes by URL manipulation.
 *
 * Security:
 *   - URL filename is validated against `CroppedCache::FILENAME_PATTERN`
 *     before any filesystem operation. NUL/CR/LF/`..` cannot pass the
 *     pattern.
 *   - The cache file is served via `file_get_contents` + manual headers,
 *     never `include` or `readfile` of an unvalidated path.
 *   - `X-Content-Type-Options: nosniff` so a browser can't be tricked
 *     into rendering a misnamed file as HTML/JS even if one slipped
 *     past validation.
 */
final class CroppedServer
{
    public function __construct(private CroppedCache $cache)
    {
    }

    public static function fromConfig(string $varDir): self
    {
        return new self(new CroppedCache($varDir . '/cache/img-cropped'));
    }

    /**
     * Handle a request whose path (after BASE strip) starts with
     * "img-c/". Sends a complete response and returns when done.
     */
    public function handle(string $path): void
    {
        // Strip the "img-c/" prefix.
        $filename = substr($path, 6);
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, "\0")) {
            $this->fail(400);
            return;
        }
        if (preg_match(CroppedCache::FILENAME_PATTERN, $filename) !== 1) {
            $this->fail(400);
            return;
        }

        $cachedPath = $this->cache->pathFor($filename);
        if (!is_file($cachedPath)) {
            // File does not exist — either never generated, or already
            // swept away as orphan. Either way: 404.
            $this->fail(404);
            return;
        }

        $this->serveCached($cachedPath, $this->mimeFromFilename($filename));
    }

    /**
     * Serve a cache file with ETag + far-future cache headers. Cache
     * files are write-once-then-immutable per (source, params) hash,
     * so `Cache-Control: immutable` is correct — once a URL exists,
     * its content cannot change.
     */
    private function serveCached(string $path, string $mime): void
    {
        $mtime = @filemtime($path);
        $size  = @filesize($path);
        if ($mtime === false || $size === false) {
            $this->fail(500);
            return;
        }
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

        $clientEtag = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            http_response_code(304);
            return;
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . $size);
            header('Cache-Control: public, max-age=31536000, immutable');
            header('ETag: ' . $etag);
            header('X-Content-Type-Options: nosniff');
        }
        // Stream the bytes via readfile() instead of slurping them into
        // memory and echo'ing — bounds the worker's per-request memory
        // by PHP's output buffer size, not by the cropped image's
        // pixel area. A cache hit on a large variant under parallel
        // load no longer multiplies file_get_contents memory pressure.
        if (@readfile($path) === false) {
            // Headers already on the wire; can't change status. Log so
            // the operator notices a degraded response.
            \H42\WhimCMS\Log::error('CroppedServer: readfile failed', ['path' => $path]);
        }
    }

    /**
     * Resolve the response Content-Type from the filename's extension.
     * The filename has already been pattern-validated so the ext is
     * one of the four known image types.
     */
    private function mimeFromFilename(string $filename): string
    {
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'application/octet-stream',
        };
    }

    private function fail(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $code;
    }
}
