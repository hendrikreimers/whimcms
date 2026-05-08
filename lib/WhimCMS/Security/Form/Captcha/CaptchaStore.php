<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Form\Captcha;

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
 *   var/state/captcha-used/.last-prune
 *
 * One file per accepted pair; file contents are the unix timestamp of
 * acceptance (used for retention pruning). Auto-cleanup runs at most
 * once per minute (or per `max_age` if shorter) — opportunistic rather
 * than via cron so a fresh deployment needs no extra wiring.
 *
 * Race-safety: `fopen($path, 'x')` is O_EXCL on POSIX — atomic
 * create-only. A second process trying to consume the same pair gets
 * `false` from fopen, which we treat as replay.
 */
final class CaptchaStore
{
    private string $dir;
    private int $maxAge;

    public function __construct(string $stateDir, int $maxAge)
    {
        $this->dir    = rtrim($stateDir, '/\\') . '/captcha-used';
        $this->maxAge = max(1, $maxAge);
    }

    /**
     * Mark a (token, nonce) pair as consumed. Returns true if this is
     * the first time we see the pair (caller may proceed), false if
     * the pair has already been used (caller should reject as replay).
     */
    public function consume(string $token, string $nonce, ?int $now = null): bool
    {
        $now = $now ?? time();
        $this->ensureDir();
        $this->pruneIfStale($now);

        $key  = substr(hash('sha256', $token . "\0" . $nonce), 0, 32);
        $path = $this->dir . '/' . $key;

        // Atomic create-only — succeeds exactly once per (token, nonce).
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            // File already exists → replay (or, vanishingly rare, hash
            // prefix collision; either way reject is the safe default).
            return false;
        }
        @fwrite($fh, (string)$now);
        @fclose($fh);
        @chmod($path, 0600);
        return true;
    }

    /**
     * Run a prune pass at most once per minute (or per max_age if that's
     * shorter). The marker file's mtime gates the rate; touching it
     * resets the next allowed prune time.
     */
    private function pruneIfStale(int $now): void
    {
        $marker = $this->dir . '/.last-prune';
        $interval = min(60, $this->maxAge);
        $last = is_file($marker) ? (int)@filemtime($marker) : 0;
        if ($now - $last < $interval) {
            return;
        }
        // Touch first so concurrent requests bail out of pruning.
        @touch($marker, $now);
        @chmod($marker, 0600);
        $this->prune($now);
    }

    /**
     * Drop entries whose mtime is older than maxAge. The store contains
     * only (hash → timestamp) pairs so a flat scandir is cheap enough
     * for the volumes this site sees.
     */
    private function prune(int $now): void
    {
        $entries = @scandir($this->dir);
        if ($entries === false) {
            return;
        }
        $cutoff = $now - $this->maxAge;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '.last-prune') {
                continue;
            }
            $path = $this->dir . '/' . $name;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
            }
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create captcha-used dir: {$this->dir}");
        }
    }
}
