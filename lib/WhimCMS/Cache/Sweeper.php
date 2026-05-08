<?php
declare(strict_types=1);

namespace H42\WhimCMS\Cache;

use H42\WhimCMS\Log;

/**
 * Abstract base for time-gated, lock-protected cache sweepers.
 *
 * Subclasses implement sweep() to do the actual cleanup. The base class
 * handles every concern that's the same across cache types:
 *
 *   - sentinel-mtime gating: run at most once per intervalSeconds
 *   - exclusive non-blocking lock so two parallel requests can't both
 *     trigger a full sweep — the second backs off silently
 *   - root-confined cache-dir realpath at construction
 *   - safe path utilities that refuse to delete anything outside the
 *     cache dir, refuse symlinks, refuse non-regular files
 *
 * Failure mode is best-effort: anything thrown inside sweep() is logged
 * and suppressed, never propagated to the calling render path. Cache
 * cleanup is hygiene, not correctness — a render request must never
 * fail because the sweeper hit an issue.
 *
 * Security invariants enforced here, audit-checkable:
 *
 *   1. cacheDir is realpath-checked at construction. Operations are
 *      rooted at the canonical path, never the user-supplied string.
 *   2. safeUnlinkFile() refuses any path whose realpath does not start
 *      with cacheRealDir + DIRECTORY_SEPARATOR. Refuses symlinks via
 *      lstat. Refuses non-regular-file modes (devices, sockets, fifos).
 *   3. safeRemoveDir() refuses non-directory and symlinked-directory
 *      modes via lstat. Recurses with a hard depth cap (16) so a
 *      pathologically nested cache layout cannot blow the call stack.
 *   4. Null-byte rejection in path arguments (defence in depth).
 *   5. The sweep's filesystem effects are entirely confined to
 *      cacheRealDir. Even a forged cache file with a malicious source
 *      path can't trick us into deleting outside this tree, because we
 *      only delete cache files (whose paths we constructed ourselves)
 *      and never use cache-file content as a path argument to a
 *      destructive call.
 */
abstract class Sweeper
{
    /** Hard cap on recursive directory descent during safeRemoveDir(). */
    private const MAX_RECURSION_DEPTH = 16;

    /** Floor on the configured interval — even at 0 we run at most once a minute. */
    private const MIN_INTERVAL_SECONDS = 60;

    protected string $cacheDir;
    protected string $cacheRealDir;
    protected string $sentinelPath;
    protected int $intervalSeconds;

    /**
     * @param string $projectRoot Absolute path of the project root. The
     *               cacheDir's realpath must sit strictly under this
     *               root, otherwise the sweeper refuses to operate at
     *               all (cacheRealDir stays empty, sweepIfDue is a no-op).
     *               This guards against a misconfigured cacheDir that
     *               points — directly or via a symlink — outside the
     *               project tree. SSH access is still required to plant
     *               such a symlink, but treating "filesystem write =
     *               total compromise" as an excuse to skip containment
     *               is exactly the bad audit habit we don't want.
     */
    public function __construct(string $cacheDir, string $sentinelPath, int $intervalSeconds, string $projectRoot)
    {
        $real     = realpath($cacheDir);
        $rootReal = realpath($projectRoot);
        // Tolerate a missing cache dir at construction time. The cache
        // layer creates it on first write; sweepIfDue() short-circuits
        // until then. But once it does exist, it must sit under the
        // project root — even via realpath resolution, no symlink trick
        // gets us out of the tree.
        if ($real !== false
            && $rootReal !== false
            && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)
        ) {
            $this->cacheRealDir = $real;
        } else {
            $this->cacheRealDir = '';
        }
        $this->cacheDir        = rtrim($cacheDir, '/\\');
        $this->sentinelPath    = $sentinelPath;
        $this->intervalSeconds = max(self::MIN_INTERVAL_SECONDS, $intervalSeconds);
    }

    /**
     * Run sweep() iff the sentinel mtime says enough time has passed
     * AND we can grab an exclusive non-blocking lock on the sentinel.
     * Otherwise short-circuit silently.
     *
     * Touches the sentinel after sweep() returns (success or failure)
     * so a perpetually-failing sweep cannot hammer every request — the
     * next attempt is intervalSeconds away.
     */
    public function sweepIfDue(): void
    {
        if ($this->cacheRealDir === '') {
            return;  // cache dir does not exist yet, nothing to sweep
        }

        // Cheap mtime check before doing any IO for the lock
        $mtime = @filemtime($this->sentinelPath);
        if ($mtime !== false && (time() - $mtime) < $this->intervalSeconds) {
            return;
        }

        // Open and lock the sentinel. 'c' creates if missing without
        // truncating an existing file — important so the mtime survives
        // re-checks under contention.
        $fp = @fopen($this->sentinelPath, 'c');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return;  // another process is sweeping right now
        }

        try {
            // Re-check mtime inside the lock — another process may have
            // finished a sweep between our cheap check and our acquire.
            clearstatcache(true, $this->sentinelPath);
            $mtime = @filemtime($this->sentinelPath);
            if ($mtime !== false && (time() - $mtime) < $this->intervalSeconds) {
                return;
            }
            $this->sweep();
        } catch (\Throwable $e) {
            Log::error('Cache sweep failed', [
                'sweeper' => static::class,
                'class'   => $e::class,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        } finally {
            @touch($this->sentinelPath);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Do the actual cleanup. Called from inside the sentinel lock with
     * cacheRealDir guaranteed non-empty.
     */
    abstract protected function sweep(): void;

    /**
     * Delete a regular file iff its realpath sits strictly inside
     * cacheRealDir. Refuses symlinks (lstat-based, no following).
     * Refuses non-regular-file modes.
     *
     * Returns true on successful unlink, false on any safety check
     * failure or unlink IO error. Never throws.
     */
    protected function safeUnlinkFile(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }
        // lstat first — determines the inode type WITHOUT following
        // symlinks. If $path is itself a symlink, we refuse here.
        $lstat = @lstat($path);
        if ($lstat === false) {
            return false;
        }
        // S_IFMT mask = 0xF000; regular file = S_IFREG = 0x8000
        if (($lstat['mode'] & 0xF000) !== 0x8000) {
            return false;  // refuse symlinks (0xA000), dirs, devices, sockets, fifos
        }
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        if (!str_starts_with($real, $this->cacheRealDir . DIRECTORY_SEPARATOR)) {
            Log::warn('Sweeper refused to unlink path outside cache dir', [
                'sweeper'   => static::class,
                'path'      => $path,
                'real'      => $real,
                'cache_dir' => $this->cacheRealDir,
            ]);
            return false;
        }
        return @unlink($real);
    }

    /**
     * Recursively delete a directory iff its realpath sits strictly
     * inside cacheRealDir. Refuses symlinked directories. Recurses with
     * a hard depth cap of 16 levels.
     *
     * Each entry inside the directory goes through its own type check
     * before being unlinked / recursed into. Symlinks inside the
     * directory are skipped with a warning (don't follow them, don't
     * delete them).
     */
    protected function safeRemoveDir(string $path, int $depth = 0): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }
        if ($depth > self::MAX_RECURSION_DEPTH) {
            Log::warn('Sweeper hit max recursion depth, aborting subtree', [
                'sweeper' => static::class,
                'path'    => $path,
            ]);
            return false;
        }

        $lstat = @lstat($path);
        if ($lstat === false) {
            return false;
        }
        // S_IFDIR = 0x4000. Refuse anything else — including S_IFLNK
        // pointing at a directory.
        if (($lstat['mode'] & 0xF000) !== 0x4000) {
            return false;
        }
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        if (!str_starts_with($real, $this->cacheRealDir . DIRECTORY_SEPARATOR)) {
            Log::warn('Sweeper refused to remove dir outside cache dir', [
                'sweeper'   => static::class,
                'path'      => $path,
                'real'      => $real,
                'cache_dir' => $this->cacheRealDir,
            ]);
            return false;
        }

        $entries = @scandir($real);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $child = $real . DIRECTORY_SEPARATOR . $e;
            $childLstat = @lstat($child);
            if ($childLstat === false) {
                continue;
            }
            $mode = $childLstat['mode'] & 0xF000;
            if ($mode === 0x8000) {
                $this->safeUnlinkFile($child);
            } elseif ($mode === 0x4000) {
                $this->safeRemoveDir($child, $depth + 1);
            } else {
                // Symlinks (0xA000), devices, sockets, fifos — skip,
                // never follow, never delete.
                Log::warn('Sweeper skipped non-regular entry in cache subtree', [
                    'sweeper' => static::class,
                    'path'    => $child,
                    'mode'    => sprintf('%o', $childLstat['mode']),
                ]);
            }
        }
        return @rmdir($real);
    }
}
