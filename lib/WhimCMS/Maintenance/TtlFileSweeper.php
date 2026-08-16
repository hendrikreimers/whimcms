<?php
declare(strict_types=1);

namespace H42\WhimCMS\Maintenance;

use H42\WhimCMS\Cache\Sweeper;
use H42\WhimCMS\Log;

/**
 * Generic retention sweeper for the flat state-file stores: delete
 * every file in ONE directory that matches ONE filename pattern and
 * whose mtime is older than ONE TTL. Nothing else, ever.
 *
 * This replaces the per-writer retention that the state stores never
 * had (`var/state/ratelimit/`, `captcha-miss/`, …) or carried inline
 * (`captcha-used/`). It deliberately does NOT replace the functional
 * pruning inside the writers — dropping expired entries from a
 * rate-limit window on each hit is correctness logic and stays where
 * it is. This class deletes dead FILES, never entries in files.
 *
 * Why an mtime TTL is provably safe for the window-keyed stores: the
 * writers rewrite the file on every hit, so mtime = time of the last
 * hit, and every timestamp inside the file is ≤ mtime. Once
 * `now − mtime > window`, every entry is expired by definition — the
 * file is dead weight. The configured TTL is always window + margin,
 * so a live counter is unreachable. The same argument covers admin
 * session files (rewritten on every authenticated request, so an
 * active session keeps a fresh mtime).
 *
 * Guard rails, mostly inherited from the audited `Cache\Sweeper` base:
 * realpath containment under the project root, lstat-based refusal of
 * symlinks and non-regular files, sentinel-gated + lock-protected runs,
 * failures logged and suppressed. Added here:
 *
 *   - filename allowlist (regex): anything not shaped like the store's
 *     own files (`.htaccess`, stray tmp files, the legacy `.last-prune`
 *     marker) is never even considered
 *   - deletion cap per run: an attack backlog of tens of thousands of
 *     files converges over several runs instead of making one visitor
 *     pay for the whole cleanup. Mind the drain arithmetic when
 *     wiring: runs are sentinel-paced, so the drain rate is
 *     cap × runs/day PER STORE (review finding W0-A3 — with a daily
 *     interval that was only 500/day; the kernels wire these sweepers
 *     hourly, i.e. up to 12 000/day/store). Do NOT read that number as
 *     "more than an attacker can produce": the right unit is BUCKETS
 *     an attacker can obtain, not requests per bucket, and a single
 *     /48 delegation is 65 536 /64s — well above 12 000. The cap
 *     bounds what one visitor pays for the cleanup, not what a flood
 *     costs us
 *   - TTL floor of one hour: a misconfigured 0 / negative TTL must not
 *     turn into "delete everything now" (same rationale as
 *     `CroppedCacheSweeper`'s floor)
 */
final class TtlFileSweeper extends Sweeper
{
    /** Upper bound on deletions per run — backlog converges across runs. */
    private const MAX_DELETIONS_PER_RUN = 500;

    /** Floor on the TTL — never interpret misconfig as "wipe the store". */
    private const MIN_TTL_SECONDS = 3600;

    private string $filenamePattern;
    private int $ttlSeconds;

    public function __construct(
        string $dir,
        string $sentinelPath,
        int $intervalSeconds,
        string $projectRoot,
        string $filenamePattern,
        int $ttlSeconds,
    ) {
        parent::__construct($dir, $sentinelPath, $intervalSeconds, $projectRoot);
        $this->filenamePattern = $filenamePattern;
        $this->ttlSeconds      = max(self::MIN_TTL_SECONDS, $ttlSeconds);
    }

    protected function sweep(): void
    {
        $entries = @scandir($this->cacheRealDir);
        if ($entries === false) {
            return;
        }
        $cutoff  = time() - $this->ttlSeconds;
        $deleted = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match($this->filenamePattern, $name) !== 1) {
                continue;  // not shaped like this store's files — never touch
            }
            $path  = $this->cacheRealDir . DIRECTORY_SEPARATOR . $name;
            $lstat = @lstat($path);
            if ($lstat === false) {
                continue;
            }
            // Regular files only; symlinks/dirs/devices are skipped here
            // and would be refused again inside safeUnlinkFile().
            if (($lstat['mode'] & 0xF000) !== 0x8000) {
                continue;
            }
            if ($lstat['mtime'] >= $cutoff) {
                continue;  // provably still (potentially) live — keep
            }
            if ($this->safeUnlinkFile($path) && ++$deleted >= self::MAX_DELETIONS_PER_RUN) {
                // warn, not info: a hit cap means a backlog is draining —
                // an operator at the default 'error' level still won't
                // see it, but anyone debugging growth will.
                Log::warn('TtlFileSweeper: per-run deletion cap reached, rest next run', [
                    'dir' => $this->cacheRealDir,
                    'cap' => self::MAX_DELETIONS_PER_RUN,
                ]);
                break;
            }
        }
    }
}
