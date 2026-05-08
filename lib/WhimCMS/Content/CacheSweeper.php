<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

use H42\WhimCMS\Cache\Sweeper;

/**
 * Cleanup pass for `var/cache/content/`.
 *
 * Walks every <sha256>.cache file, parses it via the same HMAC-verified
 * decode path as the hot render path, and drops the cache file iff its
 * recorded source path no longer exists on disk. Also drops cache files
 * that don't have a `source` field — corrupt entries or files written
 * by a previous version of the loader using a different format.
 *
 * No `include`, ever
 * ------------------
 * Cache files are HMAC-signed JSON with extension `.cache` (see
 * PageLoader docblock for the rationale). The sweeper reads them as
 * raw bytes, verifies the HMAC, then `json_decode`s the payload. A
 * file with the wrong HMAC is treated as orphan and removed.
 *
 * Filename allowlist
 * ------------------
 * Only files matching `^[a-f0-9]{64}\.cache$` are considered. Anything
 * else (a stray `.htaccess`, a temporary `*.tmp.<rand>`, leftover `.php`
 * files from the pre-HMAC cache format) is skipped from inspection but
 * left alone — the parent sweepDir's safe-unlink would refuse them
 * anyway.
 *
 * The `source` field, used safely
 * -------------------------------
 * The cache payload stores the source path as a string. The sweeper
 * passes it to `is_file()` only — never to `unlink()`, `realpath()`,
 * `include()`, `file_put_contents()`, or any other write/exec sink. If
 * the field were forged with `'source' => '/etc/passwd'`, the worst
 * outcome is we keep a cache file we should have dropped (because
 * /etc/passwd exists). The file we delete is always the cache file
 * itself — whose path the sweeper constructed from the cache dir +
 * scandir entry. With HMAC verification a forged `source` is also
 * already filtered out before reaching this code.
 */
final class CacheSweeper extends Sweeper
{
    private const FILENAME_PATTERN = '/^[a-f0-9]{64}\.cache$/';

    /** Application secret used to verify the HMAC on cache reads. */
    private string $secret;

    public function __construct(
        string $cacheDir,
        string $sentinelPath,
        int $intervalSeconds,
        string $projectRoot,
        string $secret,
    ) {
        parent::__construct($cacheDir, $sentinelPath, $intervalSeconds, $projectRoot);
        $this->secret = $secret;
    }

    protected function sweep(): void
    {
        $entries = @scandir($this->cacheRealDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match(self::FILENAME_PATTERN, $name) !== 1) {
                continue;  // not shaped like our cache files — never touch
            }
            $cachePath = $this->cacheRealDir . DIRECTORY_SEPARATOR . $name;
            if ($this->isOrphan($cachePath)) {
                $this->safeUnlinkFile($cachePath);
            }
        }
    }

    /**
     * A cache entry is orphan iff one of:
     *
     *   - file is unreadable
     *   - HMAC verification fails (planted or corrupted file)
     *   - JSON decode fails / payload is not an array
     *   - 'source' field missing or non-string
     *   - 'source' file no longer exists at the recorded path
     *
     * The 'source' value is consumed only by is_file(). It never
     * reaches a write/delete sink — see the class docblock for the
     * rationale.
     */
    private function isOrphan(string $cachePath): bool
    {
        $raw = @file_get_contents($cachePath);
        if ($raw === false) {
            return true;
        }
        $data = PageLoader::verifyAndDecode($raw, $this->secret);
        if ($data === null) {
            return true;
        }
        $source = $data['source'] ?? null;
        if (!is_string($source) || $source === '') {
            return true;
        }
        return !is_file($source);
    }
}
