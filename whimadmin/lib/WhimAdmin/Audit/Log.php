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
 * Stored at `whimadmin/var/logs/audit.log`. No rotation in v1; pair
 * with logrotate for production. The file is opened with `LOCK_EX`
 * around the append so concurrent requests don't interleave records.
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
        // FILE_APPEND + LOCK_EX gives atomic line-level append.
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        @chmod($this->path, 0o600);
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
