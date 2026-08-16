<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Form\Captcha;

use H42\WhimCMS\Log;

/**
 * Single-use store for solved captcha challenges.
 *
 * The Captcha class itself is stateless (HMAC-signed, time-windowed) so
 * one solved (token, nonce) pair would otherwise be replayable for the
 * full `max_age` window. This store closes that gap by recording every
 * accepted pair and refusing repeats.
 *
 * Storage layout:
 *   var/state/captcha-used/<sha256(token||nonce)[0..32]>
 *
 * One file per accepted pair; file contents are the unix timestamp of
 * acceptance. Retention is handled by the kernel-wired
 * `Maintenance\TtlFileSweeper` (TTL = captcha.max_age + margin) — the
 * previous in-writer prune pass and its `.last-prune` marker are gone;
 * a leftover marker file from an older deployment is inert and outside
 * the sweeper's filename allowlist.
 *
 * Replay-safety does NOT depend on retention: a marker only has to
 * exist for the `max_age` window in which its token is still
 * signature-valid. Keeping it longer is harmless; deleting it before
 * expiry is what the sweeper's TTL margin rules out.
 *
 * Race-safety: `fopen($path, 'x')` is O_EXCL on POSIX — atomic
 * create-only. A second process trying to consume the same pair gets
 * `false` from fopen — but so does a process that simply cannot write
 * (quota, fd limit, read-only mount). The two are told apart before a
 * verdict is returned; see consume().
 */
final class CaptchaStore
{
    /** First sighting of this (token, nonce) — the caller may proceed. */
    public const CONSUMED = 'consumed';

    /** The marker already exists — a genuine reuse of a solved captcha. */
    public const REPLAY = 'replay';

    /** The marker could not be written. Refuse, but do not punish. */
    public const UNAVAILABLE = 'unavailable';

    private string $dir;

    public function __construct(string $stateDir)
    {
        $this->dir = rtrim($stateDir, '/\\') . '/captcha-used';
    }

    /**
     * Mark a (token, nonce) pair as consumed. Returns CONSUMED, REPLAY
     * or UNAVAILABLE — this method reports what happened, the caller
     * decides what it costs.
     *
     * It used to return bool, which made REPLAY and UNAVAILABLE
     * indistinguishable: a state directory that had gone unwritable
     * looked exactly like a replay, and the contact pipeline answered
     * both with a Blocklist strike. The site then punished its own
     * visitors for its own disk. Every non-CONSUMED verdict still
     * REFUSES the submission — single-use enforcement is not
     * negotiable — but only a genuine replay is punished.
     */
    public function consume(string $token, string $nonce, ?int $now = null): string
    {
        $now = $now ?? time();
        $this->ensureDir();

        $key  = substr(hash('sha256', $token . "\0" . $nonce), 0, 32);
        $path = $this->dir . '/' . $key;

        // Atomic create-only — succeeds exactly once per (token, nonce).
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            // Tell "the marker is already there" apart from "we could
            // not create it". What actually lands in the second case:
            // EDQUOT, EMFILE/ENFILE, EROFS, EACCES, ENOENT (the dir
            // vanished after ensureDir), open_basedir, and ENOSPC when
            // even the metadata write fails. NOT the plain out-of-space
            // case: with free inodes the create succeeds and only the
            // fwrite below fails, leaving a zero-byte marker — harmless,
            // because single-use enforcement reads existence, not size.
            //
            // If the retention sweeper removes the marker between the
            // failed fopen and this check, the verdict is UNAVAILABLE
            // rather than REPLAY. That is the harmless direction: the
            // submission is refused either way, only the punishment is
            // withheld.
            if (is_file($path)) {
                // Existing marker → replay (or, vanishingly rare, a hash
                // prefix collision; either way refusing is correct).
                return self::REPLAY;
            }
            // Logged HERE, not at the call site: `@fopen` swallowed the
            // warning, and error_get_last() is only trustworthy right
            // after it. The controller knows neither path nor errno.
            // Error level because log_level ships as 'error' and this is
            // the one case in this class an operator must actually see.
            $err = error_get_last();
            Log::error('CaptchaStore: cannot record single-use marker', [
                'path'   => $path,
                'reason' => is_array($err) ? (string)($err['message'] ?? '') : '',
            ]);
            return self::UNAVAILABLE;
        }
        @fwrite($fh, (string)$now);
        @fclose($fh);
        @chmod($path, 0600);
        return self::CONSUMED;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create captcha-used dir: {$this->dir}");
        }
    }
}
