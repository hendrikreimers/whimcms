<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

use H42\WhimCMS\Log as CoreLog;

/**
 * Stateful, file-backed session for whimadmin.
 *
 *   Cookie:   whimadmin_sid = <id>.<hmac>           (HMAC binds id to client)
 *   On disk:  whimadmin/var/state/auth/sessions/<id>.json
 *               {
 *                 "user":       "<username>",
 *                 "stage":      "pre-otp" | "authed",
 *                 "issued":     <ts>,
 *                 "last":       <ts>,
 *                 "bind_key":   "<hex>",   # binds to ip+ua at issue time
 *                 "csrf_seed":  "<hex>",   # UNUSED — see note below
 *               }
 *
 * ⚠️ `csrf_seed` is written, parsed and carried in the type signatures,
 * but NOTHING consumes it. Http\Csrf takes only (secret, clientIp,
 * userAgent) and derives its bind key from ip+ua alone, so an admin CSRF
 * token is NOT session-scoped: it stays valid for its full 8 h lifetime
 * across a logout. This field was previously documented as "tied to the
 * session for token issue", which is untrue.
 *
 * Left in place rather than wired up, deliberately. Wiring it would need
 * a null-seed branch for three of the six form ids: Kernel::dispatch
 * constructs the Csrf object BEFORE loading the session, the login form
 * issues its token when no session exists yet, and the first-run setup
 * path has neither user nor session. The gain would be "token dies at
 * logout"; the attacker it would stop already needs a valid session
 * cookie, at which point they are the admin. Removing the field is the
 * other honest option and is a bigger change than it looks (two type
 * signatures plus Kernel).
 *
 * Two-stage session:
 *   - `pre-otp`: created at successful password step. The user has NOT
 *                yet completed login; only /otp accepts a session in
 *                this stage. All other authenticated routes reject it.
 *   - `authed` : after OTP verify. Full access.
 *
 * On every request:
 *   1. Read cookie. Reject malformed / unsigned / unknown id.
 *   2. Load session file. Reject if missing.
 *   3. Check absolute age vs `absolute_seconds`.
 *   4. Check idle age vs `idle_seconds`.
 *   5. Validate bind-key still matches the requesting client.
 *   6. Update `last`. Atomic write back.
 *
 * Session ID rotation:
 *   - On stage upgrade (pre-otp → authed)            → new id, anti-fixation.
 *   - On logout                                       → file deleted; the
 *     cookie is cleared by LogoutController, not here.
 *   - On idle/absolute timeout or bind mismatch      → **only** the server-side
 *     file is deleted and load() returns null. This class has no dependency
 *     on the cookie or the response layer and clears nothing; the stale
 *     cookie stays in the browser until the next login overwrites it.
 *     Harmless — the id it names no longer resolves — but the previous
 *     wording ("cookie cleared") described something this class cannot do.
 */
final class Session
{
    private const ID_BYTES        = 32; // 256 bits
    private const ID_HEX_LEN      = 64;
    private string $dir;

    public function __construct(
        private string $stateDir,
        private string $secret,
        private int $idleSeconds,
        private int $absoluteSeconds,
        private string $bindStrategy,
    ) {
        $this->dir = rtrim($stateDir, '/\\') . '/auth/sessions';
    }

    /**
     * Issue a fresh session for the given user at the given stage.
     * Returns the cookie value the caller must Set-Cookie.
     */
    public function issue(string $user, string $stage, string $clientIp, string $userAgent, ?int $now = null): string
    {
        if (!in_array($stage, ['pre-otp', 'authed'], true)) {
            throw new \InvalidArgumentException("Bad session stage: {$stage}");
        }
        $now = $now ?? time();
        $id  = bin2hex(random_bytes(self::ID_BYTES));

        $this->ensureDir();
        $payload = [
            'user'      => $user,
            'stage'     => $stage,
            'issued'    => $now,
            'last'      => $now,
            'bind_key'  => $this->deriveBindKey($clientIp, $userAgent),
            'csrf_seed' => bin2hex(random_bytes(16)),
        ];
        $this->writeAtomic($this->pathFor($id), $payload);

        return $this->signCookie($id);
    }

    /**
     * Validate + load the active session referenced by the cookie value.
     *
     * Returns the (mutable) record array on success, with `last`
     * already updated and persisted. Returns null on any failure.
     */
    public function load(string $cookieValue, string $clientIp, string $userAgent, ?int $now = null): ?array
    {
        $now = $now ?? time();
        $id  = $this->verifyAndExtractId($cookieValue);
        if ($id === null) {
            return null;
        }

        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return null;
        }

        $record = $this->readRecord($path);
        if ($record === null) {
            return null;
        }

        // Absolute timeout
        if ($now - $record['issued'] > $this->absoluteSeconds) {
            @unlink($path);
            return null;
        }
        // Idle timeout
        if ($now - $record['last'] > $this->idleSeconds) {
            @unlink($path);
            return null;
        }
        // Client binding
        $bind = $this->deriveBindKey($clientIp, $userAgent);
        if (!hash_equals($record['bind_key'], $bind)) {
            @unlink($path);
            return null;
        }

        $record['last'] = $now;
        $record['_id']  = $id; // pass through to caller for rotation/destroy
        $this->writeAtomic($path, $record);
        return $record;
    }

    /**
     * Upgrade `pre-otp` → `authed`. Rotates the session id (anti-fixation).
     * Returns the NEW cookie value.
     */
    public function upgradeToAuthed(string $oldCookieValue, string $clientIp, string $userAgent): string
    {
        $current = $this->load($oldCookieValue, $clientIp, $userAgent);
        if ($current === null || ($current['stage'] ?? '') !== 'pre-otp') {
            throw new \RuntimeException('No pre-otp session to upgrade.');
        }

        // Destroy old, issue new.
        $oldId = $current['_id'];
        @unlink($this->pathFor($oldId));

        return $this->issue((string)$current['user'], 'authed', $clientIp, $userAgent);
    }

    /**
     * Burn the session referenced by the cookie. Idempotent — calling
     * with an unknown / expired cookie is a no-op.
     */
    public function destroy(string $cookieValue): void
    {
        $id = $this->verifyAndExtractId($cookieValue);
        if ($id === null) {
            return;
        }
        $path = $this->pathFor($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ----- internals -----

    private function deriveBindKey(string $ip, string $ua): string
    {
        // Reuse the same vocabulary as core's Csrf bind strategy.
        return match ($this->bindStrategy) {
            'ip_ua' => hash('sha256', $ip . "\0" . $ua),
            'none'  => '',
            default => hash('sha256', $ua),
        };
    }

    private function signCookie(string $id): string
    {
        $sig = hash_hmac('sha256', $id, $this->secret);
        return $id . '.' . $sig;
    }

    private function verifyAndExtractId(string $cookieValue): ?string
    {
        if ($cookieValue === '' || strlen($cookieValue) > 200) {
            return null;
        }
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$id, $sig] = $parts;
        if (preg_match('/^[a-f0-9]{' . self::ID_HEX_LEN . '}$/', $id) !== 1) {
            return null;
        }
        $expected = hash_hmac('sha256', $id, $this->secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return $id;
    }

    private function pathFor(string $id): string
    {
        // Already validated as 64-hex via verifyAndExtractId; defensive
        // re-check before joining to a filesystem path.
        if (preg_match('/^[a-f0-9]{' . self::ID_HEX_LEN . '}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Bad session id.');
        }
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * @return array{user:string, stage:string, issued:int, last:int, bind_key:string, csrf_seed:string}|null
     */
    private function readRecord(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $u  = is_string($decoded['user']      ?? null) ? $decoded['user']      : null;
        $s  = is_string($decoded['stage']     ?? null) ? $decoded['stage']     : null;
        $i  = is_int   ($decoded['issued']    ?? null) ? $decoded['issued']    : null;
        $l  = is_int   ($decoded['last']      ?? null) ? $decoded['last']      : null;
        $b  = is_string($decoded['bind_key']  ?? null) ? $decoded['bind_key']  : null;
        $c  = is_string($decoded['csrf_seed'] ?? null) ? $decoded['csrf_seed'] : null;
        if ($u === null || $s === null || $i === null || $l === null || $b === null || $c === null) {
            return null;
        }
        if (!in_array($s, ['pre-otp', 'authed'], true)) {
            return null;
        }
        return ['user' => $u, 'stage' => $s, 'issued' => $i, 'last' => $l, 'bind_key' => $b, 'csrf_seed' => $c];
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create sessions dir: {$this->dir}");
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeAtomic(string $path, array $payload): void
    {
        $this->ensureDir();
        // Drop ephemeral _id helper before persisting.
        unset($payload['_id']);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Session encode failed.');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        // file_put_contents returns the BYTE COUNT — `=== false` alone
        // misses a short write on a full disk, and rename() would then
        // promote truncated JSON to the live record (silently killing
        // the session). Verify the count and remove the fragment.
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            CoreLog::lastPhpError('Session tempfile write failed or short', ['tmp' => $tmp]);
            @unlink($tmp);
            throw new \RuntimeException('Cannot write session (tempfile).');
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $path)) {
            CoreLog::lastPhpError('Session rename failed', ['tmp' => $tmp, 'target' => $path]);
            @unlink($tmp);
            throw new \RuntimeException('Cannot finalise session.');
        }
    }
}
