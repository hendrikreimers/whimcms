<?php
declare(strict_types=1);

namespace H42\WhimAdmin;

/**
 * WhimAdmin runtime config loader.
 *
 * Reads `whimadmin/config/app.php` once per request and exposes typed
 * dot-path lookups, mirroring the pattern of `H42\WhimCMS\Config` for
 * authoring symmetry. WhimAdmin keeps its own config tree separate
 * from the core's so a misconfig in one cannot silently affect the
 * other.
 *
 * Section discovery is allowlist-only: only the files named in
 * EXPECTED_SECTIONS are loaded. A stray .bak / .local copy under
 * `config/` cannot inject behaviour at boot.
 */
final class Config
{
    /**
     * Sections loaded from `<configDir>/<section>.php`. Each file must
     * `return` an associative array. Missing or non-array sections
     * fail loud at boot — silent fall-back to defaults masks misconfig.
     */
    private const EXPECTED_SECTIONS = [
        'app',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @throws \RuntimeException */
    public static function loadDir(string $dir): void
    {
        if (self::$data !== null) {
            return;
        }
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("WhimAdmin config dir not found: {$dir}");
        }

        $merged = [];
        foreach (self::EXPECTED_SECTIONS as $section) {
            $path = $real . DIRECTORY_SEPARATOR . $section . '.php';
            if (!is_file($path)) {
                throw new \RuntimeException("WhimAdmin config section missing: {$section}.php");
            }
            /** @var mixed $loaded */
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new \RuntimeException("WhimAdmin config section did not return an array: {$section}.php");
            }
            $merged = array_merge($merged, $loaded);
        }
        self::$data = $merged;
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new \LogicException('Config::loadDir() must be called before Config::get().');
        }
        if ($path === '') {
            return self::$data;
        }
        $cur = self::$data;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
                continue;
            }
            return $default;
        }
        return $cur;
    }

    public static function isLoaded(): bool
    {
        return self::$data !== null;
    }

    /**
     * Test-only: clear the cached config so a subsequent loadDir() reads
     * fresh state. Never call from production code.
     */
    public static function reset(): void
    {
        self::$data = null;
    }

    /**
     * Sanity-check the loaded config tree. Called from Kernel::bootstrap
     * right after `loadDir()`. Surfaces operator typos (negative TTL,
     * zero idle-timeout, unknown bind strategy) as a clear boot error
     * rather than letting silently-broken numbers reach the runtime.
     *
     * Hard caps below are upper-bounds, not recommendations — they
     * exist purely to refuse pathological values (negative durations,
     * 100-day TTLs, etc.) early.
     *
     * @throws \RuntimeException on any out-of-range value.
     */
    public static function validate(): void
    {
        if (self::$data === null) {
            throw new \LogicException('Config::loadDir() must be called before Config::validate().');
        }

        self::checkInt('session.idle_seconds',     60,  86400 * 30);
        self::checkInt('session.absolute_seconds', 60,  86400 * 30);
        self::checkInt('otp.ttl_seconds',          30,  3600);
        self::checkInt('otp.max_attempts',         1,   100);
        self::checkInt('otp.digits',               4,   10);
        self::checkInt('otp.daily_max',            0,   10000);
        self::checkInt('rate_limit.window_seconds', 30, 86400);
        self::checkInt('rate_limit.max_attempts',  1,   1000);
        self::checkInt('setup.token_ttl_seconds',  300, 86400 * 30);
        self::checkInt('content.history_max',      0,   1000);
        self::checkInt('recycler.sweep_interval_seconds', 0, 86400 * 30);
        self::checkInt('recycler.content_max_age_days',   0, 36500);
        self::checkInt('recycler.assets_max_age_days',    0, 36500);

        $bind = self::get('session.bind_strategy');
        if ($bind !== null && !in_array($bind, ['ip_ua', 'ua', 'none'], true)) {
            throw new \RuntimeException(
                "Config 'session.bind_strategy' must be one of: ip_ua, ua, none (got '"
                . (is_scalar($bind) ? (string)$bind : get_debug_type($bind)) . "')."
            );
        }

        $cookie = self::get('session.cookie_name');
        if ($cookie !== null) {
            if (!is_string($cookie) || preg_match('/^[a-zA-Z0-9_]{1,64}$/', $cookie) !== 1) {
                throw new \RuntimeException("Config 'session.cookie_name' has invalid shape.");
            }
        }
    }

    private static function checkInt(string $path, int $min, int $max): void
    {
        $v = self::get($path);
        if ($v === null) return;
        if (!is_int($v)) {
            throw new \RuntimeException(
                "Config '{$path}' must be an integer (got " . get_debug_type($v) . ').'
            );
        }
        if ($v < $min || $v > $max) {
            throw new \RuntimeException("Config '{$path}' = {$v} is out of range [{$min}, {$max}].");
        }
    }
}
