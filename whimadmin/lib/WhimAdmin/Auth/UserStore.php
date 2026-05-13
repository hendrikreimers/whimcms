<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

/**
 * Single-user account store.
 *
 *   File:    whimadmin/var/state/auth/user.json
 *   Format:  {"username":"...", "email":"...", "password_hash":"<argon2id>", "created":<ts>}
 *
 * WhimAdmin is intentionally single-user — adding multi-user support
 * widens the threat model (admin enumeration, privilege escalation,
 * concurrent edits with conflict resolution) far beyond the project's
 * scope. This class encapsulates the assumption: there is one record
 * or no record.
 *
 * The hash is computed with `PASSWORD_ARGON2ID` and the application's
 * default cost parameters (PHP picks them; on PHP 8.1+ defaults are
 * ≥64 MiB / ≥4 iterations / 1 thread). The `password_*` API takes
 * care of constant-time compare and rehash detection on verify.
 */
final class UserStore
{
    /**
     * Username constraint: ASCII letters/digits/underscore/dash, 3-32
     * characters. Tighter than email-as-username to keep the audit log
     * grep-friendly and reject control characters at the boundary.
     */
    public const USERNAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/';

    /** Email max length per RFC 5321 (mailbox 64 + @ + domain 255). */
    public const MAX_EMAIL_LEN = 254;

    /** Password length bounds: min 12 (NIST 800-63B), max 256 (DoS guard). */
    public const PASSWORD_MIN = 12;
    public const PASSWORD_MAX = 256;

    private string $path;

    /**
     * Process-static cache for the timing-equal dummy hash. Lazily
     * computed via `password_hash(random_plaintext, PASSWORD_ARGON2ID)`
     * the first time `verify()` is called without a user record on
     * disk. Reused for every subsequent no-user verify in the same
     * PHP-FPM worker so the Argon2id cost is paid at most once per
     * worker lifetime.
     */
    private static ?string $cachedDummyHash = null;

    public function __construct(private string $stateDir)
    {
        $authDir = rtrim($stateDir, '/\\') . '/auth';
        if (!is_dir($authDir) && !@mkdir($authDir, 0o700, true) && !is_dir($authDir)) {
            throw new \RuntimeException("Cannot create auth state dir: {$authDir}");
        }
        $this->path = $authDir . '/user.json';
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Create the single user record. Throws if one already exists —
     * this method is the ONLY write surface for user data, used by
     * the Setup flow exactly once per installation.
     */
    public function create(string $username, string $email, string $password): void
    {
        if ($this->exists()) {
            throw new \RuntimeException('User already exists.');
        }
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new \InvalidArgumentException('Invalid username.');
        }
        if (!self::isValidEmail($email)) {
            throw new \InvalidArgumentException('Invalid email.');
        }
        if (!self::isValidPassword($password)) {
            throw new \InvalidArgumentException('Invalid password.');
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false || $hash === null) {
            throw new \RuntimeException('Password hashing failed.');
        }

        $payload = [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => $hash,
            'created'       => time(),
        ];

        $this->writeAtomic($payload);
    }

    /**
     * Verify a username + password pair. Returns the user record on
     * success, null on any mismatch — no separate "user not found" vs
     * "wrong password" signal so login responses stay uniform and
     * user enumeration is blocked.
     *
     * Always runs `password_verify` against SOMETHING (a dummy hash
     * if no record exists) so the timing of "no user" and "wrong
     * password" paths matches.
     *
     * Two defence-in-depth refinements over a naive verify:
     *
     *   1. Username compare uses `hash_equals` on the SHA-256 digest of
     *      each side. `hash_equals` is constant-time only when both
     *      operands have the same length — raw `hash_equals($recordUser,
     *      $postedUser)` would fast-fail (and so leak the username's
     *      length) when lengths differ. Hashing first normalises both
     *      sides to 64 hex chars, so the compare is genuinely constant-
     *      time regardless of input lengths.
     *
     *   2. The dummy hash is computed at runtime from `password_hash(
     *      random_plaintext, PASSWORD_ARGON2ID)` — same cost parameters
     *      as a real user's hash on this host, no drift if PHP's
     *      Argon2id defaults change. The plaintext is 32 random bytes
     *      (hex-encoded), so even if the `$userMatches` guard ever
     *      regressed, no attacker can craft a password that verifies
     *      against the dummy.
     *
     * @return array{username:string, email:string, password_hash:string, created:int}|null
     */
    public function verify(string $username, string $password): ?array
    {
        $record = $this->load();

        $hashToCheck = $record['password_hash'] ?? self::dummyHash();
        $userMatches = $record !== null
            && hash_equals(hash('sha256', $record['username']), hash('sha256', $username));
        $passOk = password_verify($password, $hashToCheck);

        return ($userMatches && $passOk) ? $record : null;
    }

    /**
     * Lazily compute (and cache) an Argon2id hash of a random
     * throwaway plaintext. Used as the verify-against target when
     * no user record exists, so the timing of `verify()` is the
     * same whether the user exists or not.
     *
     * Static cache lives across instances within a single PHP
     * process, so a long-running FPM worker pays the ~hundreds-of-
     * milliseconds hashing cost at most once.
     */
    private static function dummyHash(): string
    {
        if (self::$cachedDummyHash !== null) {
            return self::$cachedDummyHash;
        }
        $plaintext = bin2hex(random_bytes(32));
        $hash = password_hash($plaintext, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            // Argon2id unavailable in this build — should never happen
            // on PHP 8.1+ default builds. Fall back to a defensive
            // sentinel that no `password_verify` can ever match,
            // keeping the no-user path returning false without leaking
            // the runtime failure to the client.
            self::$cachedDummyHash = '$argon2id$v=19$m=65536,t=4,p=1$' . bin2hex(random_bytes(16)) . '$' . bin2hex(random_bytes(32));
            return self::$cachedDummyHash;
        }
        return self::$cachedDummyHash = $hash;
    }

    /**
     * @return array{username:string, email:string, password_hash:string, created:int}|null
     */
    public function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $u = is_string($decoded['username']      ?? null) ? $decoded['username']      : null;
        $e = is_string($decoded['email']         ?? null) ? $decoded['email']         : null;
        $h = is_string($decoded['password_hash'] ?? null) ? $decoded['password_hash'] : null;
        $c = is_int   ($decoded['created']       ?? null) ? $decoded['created']       : null;
        if ($u === null || $e === null || $h === null || $c === null) {
            return null;
        }
        return ['username' => $u, 'email' => $e, 'password_hash' => $h, 'created' => $c];
    }

    public static function isValidEmail(string $email): bool
    {
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LEN) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Boolean facade over passwordPolicyError() for callers that just
     * need a yes/no.
     */
    public static function isValidPassword(string $password): bool
    {
        return self::passwordPolicyError($password) === null;
    }

    /**
     * Validate the password against the install's policy. Returns null
     * when the password is acceptable, otherwise a SPECIFIC error
     * message naming the failing rule (so the setup form can surface
     * exactly what's missing rather than a generic "Invalid password").
     *
     * Policy:
     *   - length: PASSWORD_MIN..PASSWORD_MAX (counted in Unicode chars)
     *   - at least one uppercase letter (Unicode-aware via \p{Lu})
     *   - at least one lowercase letter (Unicode-aware via \p{Ll})
     *   - at least one digit (any Unicode \p{N})
     *   - at least one "special" character (anything that is neither
     *     a letter nor a digit)
     *
     * The composition rule is deliberately strict — NIST 800-63B-3
     * actually argues against mandatory composition rules in favour of
     * length + breach-list checks, but for a single-user admin install
     * where there's no SSO, no breach-list service, and the operator
     * picks a password once and then types it rarely, locking out the
     * trivial "lowercase-letters-only" case is the right default. An
     * operator who wants a long passphrase with only letters can still
     * comply by mixing case + adding one number + one punctuation
     * mark.
     */
    public static function passwordPolicyError(string $password): ?string
    {
        $len = mb_strlen($password, 'UTF-8');
        if ($len < self::PASSWORD_MIN) {
            return 'Password must be at least ' . self::PASSWORD_MIN . ' characters long.';
        }
        if ($len > self::PASSWORD_MAX) {
            return 'Password must be at most ' . self::PASSWORD_MAX . ' characters long.';
        }
        if (preg_match('/\p{Lu}/u', $password) !== 1) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (preg_match('/\p{Ll}/u', $password) !== 1) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (preg_match('/\p{N}/u', $password) !== 1) {
            return 'Password must contain at least one digit.';
        }
        if (preg_match('/[^\p{L}\p{N}]/u', $password) !== 1) {
            return 'Password must contain at least one special character (e.g. ! @ # $ % & ? - _).';
        }
        return null;
    }

    /** @param array<string, mixed> $payload */
    private function writeAtomic(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('User record encode failed.');
        }
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            \H42\WhimCMS\Log::lastPhpError('User-record tempfile write failed', ['tmp' => $tmp]);
            throw new \RuntimeException('Cannot write user record (tempfile).');
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $this->path)) {
            \H42\WhimCMS\Log::lastPhpError('User-record rename failed', ['tmp' => $tmp, 'target' => $this->path]);
            @unlink($tmp);
            throw new \RuntimeException('Cannot finalise user record.');
        }
    }
}
