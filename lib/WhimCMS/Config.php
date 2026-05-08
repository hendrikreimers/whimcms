<?php
declare(strict_types=1);

namespace H42\WhimCMS;

/**
 * Loads the application config once and exposes typed lookups.
 *
 * Configuration lives in `config/<section>.php`, one file per concern.
 * Each section file `return`s an associative array of top-level keys;
 * the loader array_merges them in deterministic order into a single
 * tree that callers reach via dot-path lookups (e.g.
 * Config::get('routes.de.ueber')).
 *
 * Section discovery is allowlist-only: the EXPECTED_SECTIONS constant
 * names every file the loader will read. Adding a new section is a
 * two-line change here plus a new file. Glob-style discovery is
 * deliberately avoided so a stray .bak or .local copy in `config/`
 * cannot silently change runtime behaviour.
 */
final class Config
{
    /**
     * Sections loaded by Config::loadDir(), in load order.
     *
     * Files must exist at `<configDir>/<section>.php` and must return
     * an associative array. A missing or non-array file throws — this
     * is on purpose: misconfig should fail loud at boot, not silently
     * fall back to defaults.
     */
    private const EXPECTED_SECTIONS = [
        'app',
        'i18n',
        'routes',
        'content',
        'seo',
        'images',
        'mail',
        'email_protection',
        'contact',
        'security',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * Load every expected section under $dir. Idempotent — repeat calls
     * are no-ops, regardless of $dir, since the in-process config tree
     * is a process-global singleton by design.
     *
     * @throws \RuntimeException on missing dir, missing section file,
     *                           or a section file that doesn't return
     *                           an array.
     */
    public static function loadDir(string $dir): void
    {
        if (self::$data !== null) {
            return;
        }
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("Config dir not found: {$dir}");
        }

        $merged = [];
        foreach (self::EXPECTED_SECTIONS as $section) {
            $path = $real . DIRECTORY_SEPARATOR . $section . '.php';
            if (!is_file($path)) {
                throw new \RuntimeException("Config section missing: {$section}.php");
            }
            /** @var mixed $loaded */
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new \RuntimeException("Config section did not return an array: {$section}.php");
            }
            // array_merge replaces top-level keys; each file owns its own
            // keys so no collisions are expected. If two files redeclare the
            // same top-level key, the later EXPECTED_SECTIONS entry wins —
            // deterministic and reviewable.
            $merged = array_merge($merged, $loaded);
        }
        self::$data = $merged;
    }

    /**
     * Look up a value by dot-path. Returns $default on any miss.
     *
     * @param string $path  Dot-separated key path (e.g. "routes.en.about").
     * @param mixed  $default Value returned when the path doesn't resolve.
     */
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

    /** Return the entire merged config tree. */
    public static function all(): array
    {
        if (self::$data === null) {
            throw new \LogicException('Config::loadDir() must be called before Config::all().');
        }
        return self::$data;
    }

    /**
     * Whether the config has actually been loaded. Useful in test
     * scaffolding and for guard clauses.
     */
    public static function isLoaded(): bool
    {
        return self::$data !== null;
    }
}
