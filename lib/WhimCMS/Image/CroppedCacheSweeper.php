<?php
declare(strict_types=1);

namespace H42\WhimCMS\Image;

use H42\WhimCMS\Cache\Sweeper;

/**
 * Cleanup pass for `var/cache/img-cropped/`.
 *
 * Cache layout:
 *
 *     var/cache/img-cropped/<basename>-<hash>.<ext>
 *
 * Strategy — time-to-live, single criterion
 * -----------------------------------------
 *
 * Drop every cache file whose mtime is older than `maxAge` seconds.
 * Rationale:
 *
 *   - The cache filename hash is computed from `(source-path,
 *     source-mtime, params)`. A source change produces a NEW filename;
 *     the old one is never written to again, so its mtime stays at
 *     creation time and ages out under the TTL.
 *
 *   - A template that no longer references an image stops triggering
 *     regeneration. The cache file's mtime stays at creation time and
 *     ages out the same way.
 *
 *   - A template that DOES still reference an image regenerates the
 *     cache file on the first render after expiry — same hash, same
 *     filename, fresh mtime, fresh TTL window. Cost: one extra GD
 *     resize per image per `maxAge` interval.
 *
 * Empty-cache abort
 * -----------------
 * Unlike `Image\CacheSweeper`, this one does not need an asset-tree
 * walk to build a live-set — TTL is a sufficient signal on its own.
 * No risk of "delete everything" interpretation; an empty cache dir
 * is just a no-op.
 *
 * Symlink handling
 * ----------------
 * `Sweeper::safeUnlinkFile` is lstat-based and refuses any symlink,
 * any non-regular file, any path whose realpath escapes the cache
 * root. Per-file safety; no recursion needed because the cache layout
 * is flat (one directory, files only).
 */
final class CroppedCacheSweeper extends Sweeper
{
    /**
     * Floor on the configured TTL — even a misconfigured 0 / negative
     * value floors at 1 hour. We never want to interpret "TTL = 0" as
     * "drop everything immediately" because a nightly cache rebuild
     * would amplify into a per-render rebuild storm.
     */
    private const MIN_MAX_AGE = 3600;

    private int $maxAge;

    public function __construct(
        string $cacheDir,
        string $sentinelPath,
        int $intervalSeconds,
        string $rootDir,
        int $maxAge,
    ) {
        parent::__construct($cacheDir, $sentinelPath, $intervalSeconds, $rootDir);
        $this->maxAge = max(self::MIN_MAX_AGE, $maxAge);
    }

    protected function sweep(): void
    {
        $cutoff  = time() - $this->maxAge;
        $entries = @scandir($this->cacheRealDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // Skip anything that doesn't match the cache filename
            // pattern (defence in depth — a stray .tmp.* or .bak file
            // is left alone, not unlinked from under us).
            if (preg_match(CroppedCache::FILENAME_PATTERN, $name) !== 1) {
                continue;
            }
            $path  = $this->cacheRealDir . DIRECTORY_SEPARATOR . $name;
            $mtime = @filemtime($path);
            if ($mtime === false) {
                continue;
            }
            if ($mtime < $cutoff) {
                $this->safeUnlinkFile($path);
            }
        }
    }
}
