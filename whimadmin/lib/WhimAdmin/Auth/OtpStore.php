<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

/**
 * One-time password (mail-delivered) store.
 *
 * Stores at most one active code per user. Each code is:
 *   - 6 digits by default (configurable via app.php → otp.digits)
 *   - HMAC-stored (`hmac_sha256(code, secret)`); the plaintext code
 *     never persists
 *   - TTL-bounded (default 5 min)
 *   - attempt-bounded (default 5 wrong submits → invalidate)
 *
 *   Path:    whimadmin/var/state/auth/otp/<userKey>.json
 *   userKey: short HMAC of the username, so the on-disk filename
 *            doesn't echo the raw username
 *   Format:  {"hmac":"<hex>", "issued":<ts>, "ttl":<s>, "attempts":<int>, "max_attempts":<int>}
 *
 * The verify() flow is constant-time on success/failure ordering: load,
 * compare-with-hash_equals, decrement attempts ALWAYS (so "wrong code"
 * and "wrong username" cost the same in time). On success the file is
 * deleted; on attempt-exhaustion the file is also deleted.
 */
final class OtpStore
{
    private string $dir;

    public function __construct(
        private string $stateDir,
        private string $secret,
    ) {
        $this->dir = rtrim($stateDir, '/\\') . '/auth/otp';
    }

    /**
     * Generate, store, and return a fresh code for `$username`.
     *
     * Replaces any existing code for the same user — we don't keep
     * a history of issued codes; the latest wins.
     *
     * Returns the plaintext digits (caller mails them), never persisted.
     */
    public function issue(string $username, int $digits, int $ttlSeconds, int $maxAttempts, ?int $now = null): string
    {
        $now = $now ?? time();
        if ($digits < 4 || $digits > 10) {
            throw new \InvalidArgumentException('OTP digits must be 4..10.');
        }
        $code = self::randomDigits($digits);

        $this->ensureDir();
        $payload = [
            'hmac'         => hash_hmac('sha256', $code, $this->secret),
            'issued'       => $now,
            'ttl'          => $ttlSeconds,
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
        ];
        $this->writeAtomic($this->pathFor($username), $payload);
        return $code;
    }

    /**
     * Constant-time verification. Returns:
     *   - true   on match (code is then deleted; single-use)
     *   - false  on any failure (wrong code, expired, exhausted, no record)
     *
     * Always increments the attempt counter on a failure path so
     * a brute-force grinder hits the cap whether it guesses any
     * digits correctly or not.
     *
     * Serialised under an exclusive per-user lock so two parallel
     * `verify()` calls cannot both pass the loadValid + compare
     * window before either commits the incremented attempt count.
     * Without the lock, an aggressively parallelised brute-force
     * could effectively get ~N guesses per single counter increment
     * (where N = parallelism). With the lock, each guess always
     * advances the counter exactly once. Lock fopen failure fails
     * closed (returns false) so a broken state dir can't silently
     * disable the attempt cap.
     */
    public function verify(string $username, string $code, ?int $now = null): bool
    {
        $now = $now ?? time();
        $path = $this->pathFor($username);
        $lockPath = $path . '.lock';

        $this->ensureDir();
        $lockFh = @fopen($lockPath, 'c');
        if ($lockFh === false) {
            \H42\WhimCMS\Log::lastPhpError('OTP verify lock fopen failed', ['path' => $lockPath]);
            return false; // fail closed
        }
        try {
            // `flock(LOCK_EX)` blocks on POSIX-conformant systems but
            // can return false on rare paths (signal interrupt, NFS
            // without proper lockd, OS resource exhaustion). Without
            // the lock, two parallel `verify()` calls could both pass
            // `loadValid` + `hash_equals` before either commits the
            // incremented attempt counter — re-opening the
            // brute-force-multiplier hole this method exists to close.
            // Fail closed so the absence of the lock is never silent.
            if (!@flock($lockFh, LOCK_EX)) {
                \H42\WhimCMS\Log::lastPhpError('OTP verify flock acquire failed', ['path' => $lockPath]);
                return false;
            }
            @chmod($lockPath, 0o600);

            $record = $this->loadValid($path, $now);
            if ($record === null) {
                return false;
            }

            $expected = hash_hmac('sha256', $code, $this->secret);
            $match    = hash_equals($record['hmac'], $expected);

            if ($match) {
                @unlink($path);
                return true;
            }

            $record['attempts']++;
            if ($record['attempts'] >= $record['max_attempts']) {
                @unlink($path);
                return false;
            }
            $this->writeAtomic($path, $record);
            return false;
        } finally {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }
    }

    /**
     * Cancel any active code for the user. Used on logout from the
     * pre-OTP state and on forced session reset.
     */
    public function clear(string $username): void
    {
        $path = $this->pathFor($username);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ----- internals -----

    /**
     * @return array{hmac:string, issued:int, ttl:int, attempts:int, max_attempts:int}|null
     */
    private function loadValid(string $path, int $now): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $hmac = is_string($decoded['hmac']         ?? null) ? $decoded['hmac']         : null;
        $iss  = is_int   ($decoded['issued']       ?? null) ? $decoded['issued']       : null;
        $ttl  = is_int   ($decoded['ttl']          ?? null) ? $decoded['ttl']          : null;
        $att  = is_int   ($decoded['attempts']     ?? null) ? $decoded['attempts']     : null;
        $max  = is_int   ($decoded['max_attempts'] ?? null) ? $decoded['max_attempts'] : null;
        if ($hmac === null || $iss === null || $ttl === null || $att === null || $max === null) {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $hmac) !== 1) {
            return null;
        }
        if ($now - $iss > $ttl) {
            @unlink($path);
            return null;
        }
        if ($att >= $max) {
            @unlink($path);
            return null;
        }
        return ['hmac' => $hmac, 'issued' => $iss, 'ttl' => $ttl, 'attempts' => $att, 'max_attempts' => $max];
    }

    /**
     * Filename for a username, derived via HMAC so the directory
     * listing doesn't echo usernames in plaintext.
     */
    private function pathFor(string $username): string
    {
        $key = substr(hash_hmac('sha256', $username, $this->secret), 0, 32);
        return $this->dir . '/' . $key . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create OTP dir: {$this->dir}");
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeAtomic(string $path, array $payload): void
    {
        $this->ensureDir();
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('OTP record encode failed.');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            \H42\WhimCMS\Log::lastPhpError('OTP tempfile write failed', ['tmp' => $tmp]);
            throw new \RuntimeException('Cannot write OTP record (tempfile).');
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $path)) {
            \H42\WhimCMS\Log::lastPhpError('OTP rename failed', ['tmp' => $tmp, 'target' => $path]);
            @unlink($tmp);
            throw new \RuntimeException('Cannot finalise OTP record.');
        }
    }

    /**
     * Cryptographically-strong random digit string of the requested length.
     */
    private static function randomDigits(int $n): string
    {
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= (string)random_int(0, 9);
        }
        return $out;
    }
}
