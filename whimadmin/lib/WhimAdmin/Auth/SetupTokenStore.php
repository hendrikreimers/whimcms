<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

/**
 * One-shot setup-token store.
 *
 * On first boot (no user record exists), the Kernel asks this store
 * to issue a setup token. The token is:
 *
 *   - 32 bytes from random_bytes, base64url-encoded
 *   - HMAC-stored on disk for verification (we keep
 *     `hmac_sha256(token, secret)`, never the token itself), with TTL
 *   - Plaintext-mirrored once into a separate sidecar file in
 *     `var/state/` so the operator can read it via SSH / SFTP
 *     without trawling the host's PHP error log (which on shared
 *     hosting is often readable by other tenants and persists beyond
 *     the token TTL in archive logs).
 *
 * The /setup endpoint validates a presented token via constant-time
 * compare against the stored HMAC. On successful setup, BOTH files
 * are deleted — single-use, no plaintext residue. Same for expiry.
 *
 *   Path (HMAC):       whimadmin/var/state/setup-token.json
 *   Path (plaintext):  whimadmin/var/state/setup-token.txt
 *   Format (HMAC):     {"hmac":"<hex>", "issued":<ts>, "ttl":<s>}
 *
 * Race-protection: token issuance is gated by an exclusive lock on
 * `<stateDir>/.setup-token.lock`, the same pattern as core's Secret
 * initialisation. Only one worker will issue the first token; later
 * workers detect the existing file inside the lock and reuse it.
 *
 * Atomic writes: tempfile + rename for both files. A partial write
 * cannot leak a half-formed token to a reader because the rename is
 * atomic on POSIX filesystems. Both files are mode 0o600.
 */
final class SetupTokenStore
{
    private const HMAC_FILENAME      = 'setup-token.json';
    private const PLAINTEXT_FILENAME = 'setup-token.txt';
    private const TOKEN_BYTES        = 32;

    private string $hmacPath;
    private string $plaintextPath;
    private string $lockPath;

    public function __construct(
        private string $stateDir,
        private string $secret,
        private int $ttlSeconds,
    ) {
        $base                 = rtrim($stateDir, '/\\');
        $this->hmacPath       = $base . '/' . self::HMAC_FILENAME;
        $this->plaintextPath  = $base . '/' . self::PLAINTEXT_FILENAME;
        $this->lockPath       = $base . '/.setup-token.lock';
    }

    /**
     * Ensure a token exists. Returns the (plaintext) token IF it was
     * just issued in this call, or null if a valid token was already
     * present. The plaintext is also written to the sidecar file
     * `setup-token.txt` so the operator can retrieve it via SSH/SFTP
     * — we never reveal previously-issued tokens via the API surface.
     */
    public function ensureIssued(?int $now = null): ?string
    {
        $now = $now ?? time();
        $this->ensureDir();

        // Lock acquisition failure fails LOUD. If `fopen('c')` on the
        // lockfile fails, the state dir is unwritable — and every
        // subsequent atomic-write inside the issuance path would
        // fail anyway. A silent fallback to the unlocked path would
        // open a race: two parallel first-run requests could each
        // issue a different random token; one process writes HMAC
        // file A then plaintext A, the other interleaves HMAC B
        // between, leaving HMAC=A but plaintext=B on disk after the
        // dust settles. The operator copies the wrong plaintext,
        // retries, "Invalid token" — confusion for no security gain.
        // Better to surface the underlying error early.
        $lockFh = @fopen($this->lockPath, 'c');
        if ($lockFh === false) {
            \H42\WhimCMS\Log::lastPhpError('SetupToken lock fopen failed', ['path' => $this->lockPath]);
            throw new \RuntimeException(
                'Cannot acquire setup-token lock. Check that whimadmin/var/state is writable by the PHP process.'
            );
        }
        try {
            // `flock(LOCK_EX)` blocks on POSIX-conformant systems but
            // can return false on rare paths (signal interrupt, NFS
            // without proper lockd, OS resource exhaustion). Without
            // the lock, two parallel first-run boots could each issue
            // a fresh token concurrently — exactly the race this
            // method was rewritten to close. Fail loud so the absence
            // of the lock isn't silently bypassed.
            if (!@flock($lockFh, LOCK_EX)) {
                \H42\WhimCMS\Log::lastPhpError('SetupToken flock acquire failed', ['path' => $this->lockPath]);
                throw new \RuntimeException(
                    'Cannot lock setup-token state. Filesystem may not support advisory locking (NFS without lockd, etc.).'
                );
            }
            $existing = $this->loadValid($now);
            if ($existing !== null) {
                return null;
            }
            return $this->writeNewToken($now);
        } finally {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
            @chmod($this->lockPath, 0o600);
        }
    }

    /**
     * Returns true if the supplied token matches the stored HMAC and
     * the record is still inside its TTL. Constant-time compare via
     * hash_equals; any failure path returns false.
     */
    public function isValid(string $token, ?int $now = null): bool
    {
        $now = $now ?? time();
        if ($token === '' || strlen($token) > 200) {
            return false;
        }
        $record = $this->loadValid($now);
        if ($record === null) {
            return false;
        }
        $expected = hash_hmac('sha256', $token, $this->secret);
        return hash_equals($record['hmac'], $expected);
    }

    /**
     * Burn the token after a successful setup. Deletes BOTH the HMAC
     * record and the plaintext sidecar so no setup material remains
     * on disk. Returns true if at least one file was removed.
     */
    public function consume(): bool
    {
        $any = false;
        if (is_file($this->hmacPath)) {
            $any = @unlink($this->hmacPath) || $any;
        }
        if (is_file($this->plaintextPath)) {
            $any = @unlink($this->plaintextPath) || $any;
        }
        return $any;
    }

    public function isPresent(): bool
    {
        return is_file($this->hmacPath);
    }

    /**
     * Where the operator can find the plaintext sidecar — exposed for
     * UI hints (FirstRunController surfaces this in the error_log
     * message and the "setup required" page).
     */
    public function plaintextPath(): string
    {
        return $this->plaintextPath;
    }

    // ----- internals -----

    /**
     * Atomically write both the HMAC record and the plaintext sidecar.
     * Returns the plaintext token to the caller.
     */
    private function writeNewToken(int $now): string
    {
        $token = $this->generateToken();
        $this->writeAtomic($this->hmacPath, json_encode([
            'hmac'   => hash_hmac('sha256', $token, $this->secret),
            'issued' => $now,
            'ttl'    => $this->ttlSeconds,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        $this->writeAtomic($this->plaintextPath, $this->renderPlaintextSidecar($token, $now));
        return $token;
    }

    private function renderPlaintextSidecar(string $token, int $issuedAt): string
    {
        $expiresIso = gmdate('c', $issuedAt + $this->ttlSeconds);
        return implode("\n", [
            'WhimAdmin · first-run setup token',
            '==================================',
            '',
            'Visit (after replacing <BASE> with your admin URL prefix):',
            '',
            '  <BASE>/setup?token=' . $token,
            '',
            'Token:    ' . $token,
            'Expires:  ' . $expiresIso . ' (UTC)',
            '',
            'This token is single-use. After successful setup, this file',
            'and the matching HMAC record will be deleted automatically.',
            'If the token expires, just reload /whimadmin/ once and a',
            'fresh token (and a fresh sidecar file) will be generated.',
            '',
        ]) . "\n";
    }

    /**
     * @return array{hmac:string, issued:int, ttl:int}|null
     */
    private function loadValid(int $now): ?array
    {
        if (!is_file($this->hmacPath)) {
            return null;
        }
        $raw = @file_get_contents($this->hmacPath);
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
        $hmac = is_string($decoded['hmac']   ?? null) ? $decoded['hmac']   : null;
        $iss  = is_int   ($decoded['issued'] ?? null) ? $decoded['issued'] : null;
        $ttl  = is_int   ($decoded['ttl']    ?? null) ? $decoded['ttl']    : null;
        if ($hmac === null || $iss === null || $ttl === null) {
            // Drop both files — the HMAC record is malformed and the
            // plaintext sidecar (if any) is now orphaned.
            $this->deleteBoth();
            return null;
        }
        if ($now - $iss > $ttl) {
            $this->deleteBoth();
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $hmac) !== 1) {
            $this->deleteBoth();
            return null;
        }
        return ['hmac' => $hmac, 'issued' => $iss, 'ttl' => $ttl];
    }

    private function deleteBoth(): void
    {
        if (is_file($this->hmacPath)) {
            @unlink($this->hmacPath);
        }
        if (is_file($this->plaintextPath)) {
            @unlink($this->plaintextPath);
        }
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function writeAtomic(string $path, string $content): void
    {
        $this->ensureDir();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            \H42\WhimCMS\Log::lastPhpError('SetupToken tempfile write failed', ['tmp' => $tmp]);
            throw new \RuntimeException("Cannot write file (tempfile): {$path}");
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $path)) {
            \H42\WhimCMS\Log::lastPhpError('SetupToken rename failed', ['tmp' => $tmp, 'target' => $path]);
            @unlink($tmp);
            throw new \RuntimeException("Cannot finalise file: {$path}");
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0o700, true) && !is_dir($this->stateDir)) {
            throw new \RuntimeException("Cannot create state dir: {$this->stateDir}");
        }
    }
}
