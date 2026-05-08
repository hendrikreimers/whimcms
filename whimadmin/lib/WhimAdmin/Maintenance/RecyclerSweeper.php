<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Maintenance;

use H42\WhimAdmin\Assets\AssetBrowser;
use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Content\HistoryStore;
use H42\WhimAdmin\Content\Recycler;

/**
 * Sentinel-gated, on-demand auto-sweep that ages out the three
 * whimadmin-managed recycle/history trees:
 *
 *   - `content/.recycler/`     soft-deleted pages
 *   - `content/.history/.../`  per-page version snapshots
 *   - `assets/.recycler/`      soft-deleted asset files / dirs
 *
 * Trigger: every authed admin request calls `sweepIfDue()`. The
 * sentinel `whimadmin/var/state/.recycler-sweep` carries the
 * mtime of the last successful run; we re-run only when the
 * configured interval has passed. So the cost in the request hot
 * path is one `filemtime` + one comparison after the operator
 * passed the auth guard.
 *
 * The sweep itself is wrapped in `flock()` on the sentinel so two
 * concurrent admin requests don't double-sweep. Failures of any
 * individual cleaner are caught and audit-logged — the sweeper
 * never propagates exceptions back up into the request handler.
 *
 * Configuration (whimadmin/config/app.php → recycler):
 *
 *   'sweep_interval_seconds'   how often to even consider sweeping (default: 86400 = once per day)
 *   'content_max_age_days'     pages-recycler + history retention   (default: 30)
 *   'assets_max_age_days'      assets-recycler retention            (default: 30)
 *
 * Setting any age to 0 means "delete every entry, regardless of
 * age". Setting an age to a very large number (e.g. 36500 = 100
 * years) effectively disables that bucket's auto-sweep. There is no
 * separate enabled/disabled flag — large age = disabled.
 */
final class RecyclerSweeper
{
    private const SENTINEL_NAME = '.recycler-sweep';

    private string $sentinelPath;

    public function __construct(
        private string $stateDir,
        private Recycler $contentRecycler,
        private HistoryStore $history,
        private AssetBrowser $assetBrowser,
        private AuditLog $audit,
        private int $intervalSeconds,
        private int $contentMaxAgeDays,
        private int $assetsMaxAgeDays,
    ) {
        $this->sentinelPath = rtrim($stateDir, '/\\') . DIRECTORY_SEPARATOR . self::SENTINEL_NAME;
    }

    /**
     * Run a sweep if the configured interval has elapsed since the
     * last one. No-op if the interval is configured to a non-positive
     * value (sweep disabled).
     */
    public function sweepIfDue(?int $now = null): void
    {
        if ($this->intervalSeconds <= 0) {
            return;
        }
        $now = $now ?? time();
        $lastRun = is_file($this->sentinelPath) ? (int)(@filemtime($this->sentinelPath) ?: 0) : 0;
        if ($lastRun !== 0 && ($now - $lastRun) < $this->intervalSeconds) {
            return;
        }

        // Acquire a non-blocking lock on the sentinel so a parallel
        // admin request that also reaches this point doesn't run a
        // second sweep at the same time.
        $this->ensureStateDir();
        $lockFh = @fopen($this->sentinelPath, 'c');
        if ($lockFh === false) {
            // Cannot lock — skip silently. Worst case the sweep
            // happens on the next request when the FS is healthy.
            return;
        }
        try {
            if (!@flock($lockFh, LOCK_EX | LOCK_NB)) {
                return;
            }
            // Re-check inside the lock — the previous holder may have
            // just finished and bumped the sentinel mtime.
            clearstatcache(true, $this->sentinelPath);
            $lastRun = (int)(@filemtime($this->sentinelPath) ?: 0);
            if ($lastRun !== 0 && ($now - $lastRun) < $this->intervalSeconds) {
                return;
            }

            $this->runSweep();

            // Touch sentinel to record run timestamp.
            @touch($this->sentinelPath, $now);
            @chmod($this->sentinelPath, 0o600);
        } finally {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }
    }

    private function runSweep(): void
    {
        $totals = ['content_recycler' => 0, 'history' => 0, 'assets_recycler' => 0];

        try {
            $totals['content_recycler'] = $this->contentRecycler->purgeOlderThan($this->contentMaxAgeDays);
        } catch (\Throwable $e) {
            $this->audit->record('sweep.fail', null, null, ['target' => 'content_recycler', 'error' => $e->getMessage()]);
        }
        try {
            $totals['history'] = $this->history->purgeOlderThan($this->contentMaxAgeDays);
        } catch (\Throwable $e) {
            $this->audit->record('sweep.fail', null, null, ['target' => 'history', 'error' => $e->getMessage()]);
        }
        try {
            $totals['assets_recycler'] = $this->assetBrowser->recyclerPurgeOlderThan($this->assetsMaxAgeDays);
        } catch (\Throwable $e) {
            $this->audit->record('sweep.fail', null, null, ['target' => 'assets_recycler', 'error' => $e->getMessage()]);
        }

        // Only audit-log when something actually happened — keeps the
        // log readable for a normal install where most days have nothing
        // to delete.
        if ($totals['content_recycler'] + $totals['history'] + $totals['assets_recycler'] > 0) {
            $this->audit->record('sweep.ok', null, null, $totals);
        }
    }

    private function ensureStateDir(): void
    {
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0o700, true) && !is_dir($this->stateDir)) {
            // Don't crash a normal request because the sweep can't run.
            // The sweep will just skip until the dir is fixed.
        }
    }
}
