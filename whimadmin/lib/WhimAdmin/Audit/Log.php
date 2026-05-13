<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Audit;

/**
 * Append-only audit log for whimadmin's security-relevant events.
 *
 * Format (one record per line, JSON-encoded):
 *
 *   {"ts":"2026-05-05T11:23:45+00:00","event":"login.password.fail","ip_hash":"...","user":"alice","detail":{...}}
 *
 * Stored at `whimadmin/var/logs/audit.log`. The file is opened with
 * `LOCK_EX` around the append so concurrent requests don't
 * interleave records.
 *
 * Size-based rotation (built-in): when the active file exceeds
 * `MAX_BYTES`, it's renamed to `audit.log.1` and a fresh `audit.log`
 * is started. Existing rotations shift up (`.1 → .2`, `.2 → .3`),
 * with the oldest (`.MAX_ROTATIONS`) dropped on overflow. Defends
 * against the deployment-without-logrotate case where the log would
 * otherwise grow unboundedly and exhaust disk. Operators who DO
 * have a host-level logrotate can keep using it on top — our
 * rotation is bound to the size threshold, theirs to time.
 *
 * IPs are HMAC-keyed via the application secret so plaintext IPs
 * never sit on disk — same posture as the core's RateLimiter and
 * MailLog. The HMAC is one-way; aggregation by IP is still possible
 * (same IP → same hash), correlation back to a person is not.
 *
 * Events that MUST be recorded (Phase 1):
 *   - setup.token.generate          a setup token was issued (server log only)
 *   - setup.token.consume           setup completed; user account created
 *   - setup.token.invalid           wrong/expired token presented to /setup
 *   - login.password.ok             username + password verified
 *   - login.password.fail           username or password mismatch
 *   - login.otp.sent                code mailed
 *   - login.otp.fail                wrong code, or expired
 *   - login.otp.ok                  authenticated session granted
 *   - login.ratelimit               IP exceeded rate window
 *   - logout                        session destroyed
 *   - session.expire                idle/absolute timeout hit
 *   - session.invalid               cookie failed signature/binding
 */
final class Log
{
    /** Size threshold at which the active log rotates. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** How many historical rotations to keep before dropping the oldest. */
    private const MAX_ROTATIONS = 3;

    private string $path;

    public function __construct(
        private string $logsDir,
        private string $secret,
    ) {
        $this->path = rtrim($logsDir, '/\\') . '/audit.log';
    }

    /**
     * Record one event.
     *
     * @param array<string, mixed> $detail  Optional structured payload.
     *                                      Must JSON-encode without errors.
     */
    public function record(
        string $event,
        ?string $clientIp = null,
        ?string $user = null,
        array $detail = [],
    ): void {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $event) !== 1) {
            // Don't accept arbitrary event names — keeps the audit
            // vocabulary tight and grep-able.
            return;
        }

        $record = [
            'ts'    => date('c'),
            'event' => $event,
        ];
        if ($clientIp !== null) {
            $record['ip_hash'] = $this->hashIp($clientIp);
        }
        if ($user !== null) {
            // Limit to 64 bytes; usernames longer than that are rejected
            // at the UserStore. This is a defensive cap for log clutter.
            $record['user'] = mb_substr($user, 0, 64, 'UTF-8');
        }
        if ($detail !== []) {
            $record['detail'] = $this->sanitizeDetail($detail);
        }

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        $this->ensureDir();
        $line = $json . "\n";

        // Rotate the active file when it exceeds the size cap. Done
        // BEFORE the append so the new write always lands in a
        // fresh (or at-least-under-cap) file. Two paths can race
        // here: A and B both pass the size check, both call rotate(),
        // and the second one cascade-renames over the first one's
        // already-rotated files — dropping the historical `.MAX_ROTATIONS`
        // slot's data. Gate the whole rotation under a non-blocking
        // exclusive lock on a sidecar file. If we can't acquire it
        // immediately, another worker is rotating right now; we skip
        // our own rotation and the next append still lands correctly
        // (file_put_contents creates a fresh active file if the
        // concurrent rotation has already moved the old one away).
        clearstatcache(true, $this->path);
        if (is_file($this->path) && @filesize($this->path) >= self::MAX_BYTES) {
            $this->rotateIfHolder();
        }

        // FILE_APPEND + LOCK_EX gives atomic line-level append.
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        @chmod($this->path, 0o600);
    }

    /**
     * Serialise the rotation cascade. Uses LOCK_EX | LOCK_NB so we
     * never block the audit-record path on a concurrent rotation —
     * if another process is rotating, our caller's filesize check
     * happens AGAIN (after the concurrent rotation moves the old
     * file aside) and so the next record we write lands in the
     * fresh active file.
     *
     * Re-checks filesize INSIDE the lock so a concurrent rotation
     * that completed between our outer check and lock acquisition
     * doesn't cause us to rotate a freshly-empty file.
     */
    private function rotateIfHolder(): void
    {
        $lockPath = $this->path . '.rotate.lock';
        $rotFh = @fopen($lockPath, 'c');
        if ($rotFh === false) {
            // Lockfile uncreatable — fall through without rotating.
            // The active log keeps growing until the next attempt;
            // operator-side logrotate (if any) still picks it up.
            return;
        }
        try {
            if (!@flock($rotFh, LOCK_EX | LOCK_NB)) {
                // Concurrent rotation in progress — yield.
                return;
            }
            @chmod($lockPath, 0o600);
            // Re-check under the lock: a parallel rotation may have
            // already moved the active log to .1 since our outer check.
            clearstatcache(true, $this->path);
            if (is_file($this->path) && @filesize($this->path) >= self::MAX_BYTES) {
                $this->rotate();
            }
        } finally {
            @flock($rotFh, LOCK_UN);
            @fclose($rotFh);
        }
    }

    /**
     * Cascade-rename the rotation chain:
     *   audit.log.{MAX_ROTATIONS-1} → audit.log.{MAX_ROTATIONS}
     *   audit.log.1                  → audit.log.2
     *   audit.log                    → audit.log.1
     *
     * Drops the oldest (`.MAX_ROTATIONS`) on overflow. Each rename
     * is atomic; the active file is missing for a microsecond
     * between the .1 rename and the next append (which `fopen('a')`s
     * a fresh file). Concurrent records during that window land in
     * the fresh file — no loss because the new append acquires its
     * own LOCK_EX before writing.
     *
     * Best-effort: failures are silenced. If a rename fails the
     * active file just keeps growing past the threshold until the
     * next attempt; the operator's separate logrotate (if any) or
     * a manual cleanup remains a backstop. We do NOT fail audit
     * records because of a rotation hiccup.
     */
    private function rotate(): void
    {
        // Drop the oldest if it exists.
        $oldest = $this->path . '.' . self::MAX_ROTATIONS;
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        // Shift .N-1 → .N, .N-2 → .N-1, …, .1 → .2
        for ($i = self::MAX_ROTATIONS - 1; $i >= 1; $i--) {
            $src = $this->path . '.' . $i;
            $dst = $this->path . '.' . ($i + 1);
            if (is_file($src)) {
                @rename($src, $dst);
                @chmod($dst, 0o600);
            }
        }
        // Move active log to .1
        if (is_file($this->path)) {
            @rename($this->path, $this->path . '.1');
            @chmod($this->path . '.1', 0o600);
        }
    }

    private function hashIp(string $ip): string
    {
        // 16 hex chars (64 bits) is plenty for aggregation/correlation
        // without making the log file enormous.
        return substr(hash_hmac('sha256', $ip, $this->secret), 0, 16);
    }

    /**
     * Best-effort scrub of structured detail. Removes obvious credential
     * keys, forces scalar values, caps depth at 1 to keep records flat.
     *
     * Redaction rule is **substring match** on the key (lower-cased), not
     * exact match — so `passwordHash`, `csrfToken`, `apiSecret`,
     * `confirmCode`, `userOtp`, `setCookie` etc. are all caught. Exact
     * match would have let `passwordHash` through; substring match is
     * the safer default for an audit log we never want to leak credentials
     * into accidentally.
     *
     * @param array<string, mixed> $detail
     * @return array<string, scalar|null>
     */
    private function sanitizeDetail(array $detail): array
    {
        $needles = ['password', 'pass', 'token', 'secret', 'code', 'otp', 'cookie', 'authorization', 'auth', 'hash', 'apikey'];
        $out = [];
        foreach ($detail as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $lower = strtolower($k);
            $redacted = false;
            foreach ($needles as $n) {
                if (str_contains($lower, $n)) {
                    $out[$k] = '[redacted]';
                    $redacted = true;
                    break;
                }
            }
            if ($redacted) continue;
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
                continue;
            }
            // Drop arrays/objects rather than recurse — keeps records flat.
            $out[$k] = '[non-scalar]';
        }
        return $out;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->logsDir) && !@mkdir($this->logsDir, 0o700, true) && !is_dir($this->logsDir)) {
            // Don't crash a request because a log dir is unavailable.
            // The web-server's error_log will see this via PHP's
            // own warning channel; we just silently skip.
            return;
        }
    }
}
