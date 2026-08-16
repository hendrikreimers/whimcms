<?php
declare(strict_types=1);

namespace H42\WhimCMS\Maintenance;

use H42\WhimCMS\Cache\Sweeper;
use H42\WhimCMS\Log;

/**
 * Retention sweeper for day-bucketed directory stores
 * (`var/state/mail-log/YYYY-MM-DD/…`): remove every direct child
 * directory whose `YYYY-MM-DD` name is older than the retention
 * window. Directory names that don't match the date shape are never
 * touched.
 *
 * This takes over — and decouples — what `MailLog::prune()` used to do
 * inline on every `record()` call. That coupling was a trap: the
 * writer's early return on `log_enabled = false` silently froze the
 * existing records forever, because the pruner only ran inside the
 * writer. Run from the maintenance Coordinator instead, retention now
 * applies regardless of whether the writer still runs.
 *
 * Deletion goes through the audited base's `safeRemoveDir()`:
 * realpath containment, lstat-based symlink refusal (a planted symlink
 * inside a day folder is skipped, never followed), recursion depth
 * cap. The hand-rolled `deleteDir()` this replaces followed directory
 * symlinks — the same divergence-from-the-hardened-pattern story as
 * the IPv6 bucketing, closed the same way: one implementation, shared.
 *
 * Semantics match the previous pruner exactly: cutoff is the day-name
 * string `date('Y-m-d', now − days·86400)`; a folder is deleted iff
 * its name sorts strictly below the cutoff. `retentionDays` is floored
 * at 0 (0 = keep only today's bucket).
 */
final class DayDirSweeper extends Sweeper
{
    /** Upper bound on directory deletions per run. */
    private const MAX_DELETIONS_PER_RUN = 500;

    private const DAY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private int $retentionDays;

    public function __construct(
        string $dir,
        string $sentinelPath,
        int $intervalSeconds,
        string $projectRoot,
        int $retentionDays,
    ) {
        parent::__construct($dir, $sentinelPath, $intervalSeconds, $projectRoot);
        $this->retentionDays = max(0, $retentionDays);
    }

    protected function sweep(): void
    {
        $entries = @scandir($this->cacheRealDir);
        if ($entries === false) {
            return;
        }
        $cutoff  = date('Y-m-d', time() - $this->retentionDays * 86400);
        $deleted = 0;
        foreach ($entries as $name) {
            if (preg_match(self::DAY_PATTERN, $name) !== 1) {
                continue;  // not a day bucket — never touch
            }
            if ($name >= $cutoff) {
                continue;  // inside the retention window
            }
            $path = $this->cacheRealDir . DIRECTORY_SEPARATOR . $name;
            if ($this->safeRemoveDir($path) && ++$deleted >= self::MAX_DELETIONS_PER_RUN) {
                Log::info('DayDirSweeper: per-run deletion cap reached, rest next run', [
                    'dir' => $this->cacheRealDir,
                    'cap' => self::MAX_DELETIONS_PER_RUN,
                ]);
                break;
            }
        }
    }
}
