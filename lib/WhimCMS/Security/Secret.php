<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security;

/**
 * Long-lived per-installation HMAC secret. Stored in a single file under
 * var/state/, generated on first request if missing. The secret is used
 * to sign CSRF tokens and to keyed-hash sensitive identifiers (IPs in
 * the rate limiter and blocklist), so plaintext IPs never sit on disk.
 *
 * The file is written 0600 where the OS supports it. The directory must
 * be web-inaccessible (var/.htaccess does that).
 */
final class Secret
{
    private const FILENAME = 'secret';
    private const BYTES    = 32; // 256 bits

    private static ?string $cached = null;

    /**
     * Load (or initialise) the application secret. Returns the raw bytes.
     * Subsequent calls within a request return the cached value.
     *
     * First-hit race: two parallel requests both seeing `is_file=false`
     * could each `random_bytes()` and `rename()` — the second rename
     * would overwrite the first secret, invalidating any tokens already
     * issued in the first request. We close that window by acquiring an
     * exclusive flock on a sibling lockfile before the existence check,
     * so only one worker actually writes. The lockfile itself is empty
     * and persists in `var/state/`; cost is one fopen + flock per
     * first-hit per process.
     */
    public static function load(string $stateDir): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $path = rtrim($stateDir, '/\\') . '/' . self::FILENAME;

        if (!is_file($path)) {
            self::ensureDir($stateDir);
            self::initialiseUnderLock($stateDir, $path);
        }

        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 16) {
            throw new \RuntimeException("Secret file unreadable or too short: {$path}");
        }
        return self::$cached = $raw;
    }

    /**
     * Hold an exclusive lock on `<stateDir>/.secret.lock` for the
     * duration of the create-if-missing window. Re-checks `is_file()`
     * inside the lock so a concurrent winner is detected and we just
     * read theirs.
     */
    private static function initialiseUnderLock(string $stateDir, string $path): void
    {
        $lockPath = rtrim($stateDir, '/\\') . '/.secret.lock';
        $lockFh = @fopen($lockPath, 'c');
        if ($lockFh === false) {
            // Filesystem refused us a handle — fall back to the unlocked
            // path. Race window is then the one we lived with before;
            // we still emit a log so the misconfig is visible.
            \error_log('[WhimCMS] Secret: cannot open lockfile, falling back to unlocked init');
            self::writeOnce($path);
            return;
        }
        try {
            @flock($lockFh, LOCK_EX);
            // Re-check inside the lock — the previous holder may have
            // finished writing while we were waiting.
            if (is_file($path)) {
                return;
            }
            self::writeOnce($path);
        } finally {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
            @chmod($lockPath, 0600);
        }
    }

    /**
     * Write a fresh secret atomically (tempfile + rename). Caller has
     * confirmed the target file is missing.
     */
    private static function writeOnce(string $path): void
    {
        $bytes = random_bytes(self::BYTES);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            \H42\WhimCMS\Log::lastPhpError('Secret tempfile write failed', ['tmp' => $tmp]);
            throw new \RuntimeException("Cannot create secret at {$path}");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            \H42\WhimCMS\Log::lastPhpError('Secret rename failed', ['tmp' => $tmp, 'target' => $path]);
            @unlink($tmp);
            // If rename failed because the target now exists, that's
            // fine — the lock should have prevented it but a non-POSIX
            // FS could still race; just read theirs.
            if (!is_file($path)) {
                throw new \RuntimeException("Cannot finalise secret at {$path}");
            }
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create state dir: {$dir}");
        }
    }
}
