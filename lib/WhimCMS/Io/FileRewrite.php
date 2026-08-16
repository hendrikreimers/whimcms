<?php
declare(strict_types=1);

namespace H42\WhimCMS\Io;

use H42\WhimCMS\Log;

/**
 * Verified in-place rewrite of a small state file on an already-open,
 * already-locked handle.
 *
 * Single shared implementation for the write step of every
 * read-modify-write state store (rate limiter, blocklist, captcha-miss
 * tracker, both mail day-counters). Before this class, all of them
 * hand-rolled the same sequence — `ftruncate(0)` → `fwrite` → `fflush`
 * — with the DESTRUCTIVE step first and the write result unchecked. On
 * a full disk or an exhausted inode quota the truncate succeeded, the
 * write did not, and the next reader decoded the empty/partial file as
 * "no entries": counters reset to zero, active blocks erased, silently.
 * Filling the disk therefore reset the site's own throttles.
 *
 * This helper inverts the order and verifies:
 *
 *   1. write the new payload at offset 0, byte-counted
 *   2. only on a complete write, truncate DOWN to the new length
 *      (drops the old tail when the payload shrank)
 *   3. on a short write, put the previous bytes back at offset 0,
 *      truncate to their length, and report failure loudly
 *
 * Why the restore is dependable where it matters: a write can
 * realistically only fail while EXTENDING the file (fresh blocks on a
 * full filesystem). The first `strlen($previousRaw)` bytes overwrite
 * already-allocated blocks in place — ext4 needs no new allocation for
 * that, which is exactly where the restore writes. Shrinking payloads
 * (the prune case) practically cannot fail. Copy-on-write filesystems
 * give no such guarantee; there the failure branch degrades to
 * detect-and-log — still strictly better than today's silent truncate.
 * (The photoshare sibling's GrantStore checks the write result and
 * logs, but does not restore; this goes the one step further.)
 *
 * Deliberately NOT here:
 *
 *   - No locking. The caller already holds LOCK_EX on `$fh`, and the
 *     whole point of rewriting on the held handle is that the lock
 *     stays anchored to the same inode across the read-modify-write —
 *     the documented invariant that rules out tmpfile+rename, whose
 *     atomic replace would orphan concurrent lockers onto a dead
 *     inode and lose updates.
 *   - No paths, no unlink, no chmod. The helper knows only the handle;
 *     everything filesystem-shaped stays with the store that owns it.
 *   - No policy. Whether a failed rewrite rejects the request
 *     (rate limiter, mail counters: fail-closed) or forfeits one
 *     increment (blocklist strike, captcha miss: fail-open) is the
 *     caller's documented decision.
 *
 * Returns true when the new payload is fully persisted. Returns false
 * on failure — with the previous content restored when possible; when
 * even the restore falls short (both writes failed), the file is left
 * truncated to the previous length and the corruption is logged here,
 * because at that point the on-disk state is neither old nor new.
 */
final class FileRewrite
{
    /**
     * @param resource $fh open handle with LOCK_EX held by the caller
     */
    public static function replace($fh, string $payload, string $previousRaw): bool
    {
        if (self::writeAll($fh, $payload) && @ftruncate($fh, strlen($payload))) {
            return true;
        }

        // Short write (or failed shrink) — put the old bytes back. The
        // restore only counts as clean if BOTH steps succeed: bytes
        // written AND the tail truncated. A successful byte-restore
        // with a failed truncate would leave previousRaw + a stale
        // payload tail = invalid JSON for the stores — exactly the
        // silent-reset this class exists to prevent, so that case must
        // be loud too (review finding W0-A4).
        $restored = self::writeAll($fh, $previousRaw)
                 && @ftruncate($fh, strlen($previousRaw));
        if (!$restored) {
            Log::error('FileRewrite: rewrite failed AND restore incomplete — state file may be inconsistent', [
                'payload_bytes'  => strlen($payload),
                'previous_bytes' => strlen($previousRaw),
            ]);
        }
        return false;
    }

    /**
     * Byte-counted write of the whole string at offset 0. `fwrite` may
     * legally write fewer bytes than asked without raising an error, so
     * a single unchecked call is not a write — this loops until done or
     * provably stuck.
     *
     * @param resource $fh
     */
    private static function writeAll($fh, string $bytes): bool
    {
        rewind($fh);
        $len   = strlen($bytes);
        $total = 0;
        while ($total < $len) {
            $n = @fwrite($fh, $total === 0 ? $bytes : substr($bytes, $total));
            if ($n === false || $n === 0) {
                @fflush($fh);
                return false;
            }
            $total += $n;
        }
        @fflush($fh);
        return true;
    }
}
