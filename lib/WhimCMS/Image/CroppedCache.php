<?php
declare(strict_types=1);

namespace H42\WhimCMS\Image;

/**
 * Filesystem-backed cache for cropped/resized image variants produced
 * by the `{% image %}` template directive.
 *
 *   Layout:
 *     var/cache/img-cropped/<basename>-<hash>.<ext>
 *
 * Flat directory, one file per (source, params) tuple. The basename
 * is a sanitised slice of the source filename — purely cosmetic, for
 * human-readable URLs in the browser dev tools — and the 12-hex-char
 * hash is the routing-relevant part. Hash inputs:
 *
 *   sha256( source-real-path + source-mtime + canonicalised-params )
 *
 * Including the mtime in the hash means a source-file change produces
 * a NEW cache filename, not a stale one — so freshness checks at
 * lookup time are unnecessary. The old cache file becomes orphaned
 * and will be picked up by the sweeper.
 *
 * Generation rule: cache files are written ONLY by the
 * `ImageDirective` at template-render time. The HTTP endpoint
 * (`CroppedServer`) is read-only — it never produces a file. That
 * means the set of valid cache filenames is bounded by what the
 * templates actually request; an attacker poking `/img-c/anything-...`
 * gets 404 unless the file already exists from a legit render.
 *
 * Filename URL pattern (used by both this cache and the server):
 *   ^[a-z0-9_-]+-[a-f0-9]{12}\.(jpe?g|png|webp|gif)$
 *
 * The pattern caps length implicitly (basename max 30 chars by
 * sanitisation, hash exactly 12, ext at most 4). Defence in depth
 * for the endpoint validator.
 */
final class CroppedCache
{
    /**
     * Length of the short hash embedded in cache filenames. 12 hex
     * chars = 48 bits — collision-safe for site asset volumes (you'd
     * need ~16 million distinct (source, params) tuples for a 50%
     * collision chance, an order of magnitude past any realistic
     * site).
     */
    public const HASH_LEN = 12;

    /**
     * URL/filename pattern. Exposed so `CroppedServer` can re-use
     * exactly the same regex when validating an inbound request.
     */
    public const FILENAME_PATTERN = '/^[a-z0-9_-]+-[a-f0-9]{12}\.(jpe?g|png|webp|gif)$/';

    /** Max length of the human-readable basename slice in a filename. */
    private const BASENAME_MAX = 30;

    public function __construct(private string $cacheDir)
    {
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Build the deterministic cache filename for a given source +
     * params + output extension. Same inputs → same filename, always.
     *
     * `$params` is the directive's normalised parameter set (already
     * validated). Order-independent: passed through `canonicalParams()`
     * so `{width:80, height:80}` and `{height:80, width:80}` produce
     * the same hash.
     *
     * @param array<string, mixed> $params
     */
    public function filenameFor(
        string $sourceRealPath,
        int $sourceMtime,
        array $params,
        string $ext,
    ): string {
        $hashInput = $sourceRealPath . '|' . $sourceMtime . '|' . self::canonicalParams($params);
        $hash      = substr(hash('sha256', $hashInput), 0, self::HASH_LEN);
        $basename  = self::safeBasename($sourceRealPath);
        return $basename . '-' . $hash . '.' . $ext;
    }

    /** Absolute path of a cache file. Exists or not. */
    public function pathFor(string $filename): string
    {
        return $this->cacheDir . '/' . $filename;
    }

    public function exists(string $filename): bool
    {
        return is_file($this->cacheDir . '/' . $filename);
    }

    /**
     * Atomically write the rendered bytes to the cache. Tempfile +
     * rename so a process kill mid-write can't leave a half-written
     * file that would be served as truth on the next request.
     */
    public function store(string $filename, string $bytes): bool
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0o700, true) && !is_dir($this->cacheDir)) {
            return false;
        }
        $target = $this->cacheDir . '/' . $filename;
        $tmp    = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Build a cosmetic filename slice from the source's basename:
     * lowercase, alphanumerics + hyphens only, capped at BASENAME_MAX.
     * Empty input falls back to `'img'` so the resulting filename
     * still matches FILENAME_PATTERN.
     */
    public static function safeBasename(string $sourceRealPath): string
    {
        $name = pathinfo($sourceRealPath, PATHINFO_FILENAME);
        $safe = strtolower((string)preg_replace('/[^a-zA-Z0-9-]+/', '-', $name));
        $safe = trim($safe, '-');
        if ($safe === '') {
            $safe = 'img';
        }
        if (strlen($safe) > self::BASENAME_MAX) {
            $safe = substr($safe, 0, self::BASENAME_MAX);
        }
        return $safe;
    }

    /**
     * Order-independent serialisation of the params map for hashing.
     * `ksort` then a fixed-format string — no `serialize()`, which
     * would embed PHP type info that could vary across versions.
     *
     * @param array<string, mixed> $params
     */
    private static function canonicalParams(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            // floats are stringified with enough precision for focus
            // values (3 decimals = ~1px on a 1000px image).
            $vStr = is_float($v)
                ? number_format($v, 3, '.', '')
                : (is_bool($v) ? ($v ? '1' : '0') : (string)$v);
            $parts[] = $k . '=' . $vStr;
        }
        return implode('&', $parts);
    }
}
