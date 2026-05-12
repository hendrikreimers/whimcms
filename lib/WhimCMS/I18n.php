<?php
declare(strict_types=1);

namespace H42\WhimCMS;

/**
 * Loads JSON dictionaries for the active language and resolves the "~"
 * path-prefix marker against the deployment base path. No cookies, no
 * session state — language is always determined by the URL.
 *
 * Usage:
 *   I18n::setDir(__DIR__ . '/../i18n');
 *   $dict = I18n::load($lang, $basePath);
 */
final class I18n
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    private static string $i18nDir = '';

    /**
     * Content directory for editor-managed overlay files
     * (`_i18n_overlay.<lang>.json`). `null` disables the overlay
     * layer entirely — the dictionary is then exactly what the
     * theme's JSON shipped, with no editor contribution merged on
     * top. Set once at boot from Kernel; leaving it null on test
     * scaffolds is fine.
     */
    private static ?string $overlayDir = null;

    public static function setDir(string $dir): void
    {
        self::$i18nDir = rtrim($dir, '/\\');
    }

    /**
     * Configure the content directory the overlay loader reads
     * `_i18n_overlay.<lang>.json` from. Pass `null` (or skip the
     * call) to disable the overlay layer. The Kernel calls this
     * once during bootstrap with the validated content path.
     */
    public static function setOverlayDir(?string $dir): void
    {
        self::$overlayDir = $dir === null ? null : rtrim($dir, '/\\');
    }

    /**
     * Pick the best supported language from an Accept-Language header,
     * falling back to $defaultLang. Tag matching is case-insensitive
     * and prefix-aware ("de-DE" matches "de").
     *
     * @param array<int, string> $supported
     */
    public static function detectFromAcceptLanguage(string $header, array $supported, string $defaultLang): string
    {
        if ($header === '' || $supported === []) {
            return $defaultLang;
        }
        // Parse "de-DE,de;q=0.8,en;q=0.7" → ordered list of codes.
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = explode(';', $part);
            $tag  = strtolower(trim($bits[0]));
            $q    = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                $param = trim($param);
                if (str_starts_with($param, 'q=')) {
                    $q = (float)substr($param, 2);
                    break;
                }
            }
            if ($tag !== '') {
                $candidates[] = ['tag' => $tag, 'q' => $q];
            }
        }
        usort($candidates, static fn($a, $b) => $b['q'] <=> $a['q']);

        foreach ($candidates as $c) {
            $tag    = $c['tag'];
            $prefix = explode('-', $tag, 2)[0];
            if (in_array($tag, $supported, true)) {
                return $tag;
            }
            if (in_array($prefix, $supported, true)) {
                return $prefix;
            }
        }
        return $defaultLang;
    }

    /**
     * Validate a candidate language code against the configured regex
     * and the supported-langs whitelist. Defence-in-depth — the regex
     * keeps malformed input out of file paths even if the whitelist
     * check is somehow bypassed in future code.
     *
     * @param array<int, string> $supported
     */
    public static function validate(string $candidate, string $pattern, array $supported): ?string
    {
        if ($candidate === '' || preg_match($pattern, $candidate) !== 1) {
            return null;
        }
        return in_array($candidate, $supported, true) ? $candidate : null;
    }

    /**
     * Load the dictionary for one language and resolve "~" path
     * placeholders against this language's URL root.
     *
     *   single-lang deployment: langRoot = $basePath
     *   multi-lang deployment:  langRoot = $basePath . "/" . $lang
     *
     * That way an entry like `"href": "~/about"` in en.json resolves to
     * `/en/about` (multi) or just `/about` (single), without templates
     * having to know which mode they're rendered in.
     *
     * Result is cached per (lang, base, singleLang) so subsequent calls
     * are free within a request.
     *
     * @return array<string, mixed>
     */
    public static function load(string $lang, string $basePath = '', bool $singleLang = false): array
    {
        \H42\WhimCMS\Content\Identifiers::assertLang($lang);
        $cacheKey = $lang . '|' . $basePath . '|' . ($singleLang ? '1' : '0');
        if (isset(self::$loaded[$cacheKey])) {
            return self::$loaded[$cacheKey];
        }
        $path = self::$i18nDir . '/' . $lang . '.json';
        $real = realpath($path);
        $rootReal = realpath(self::$i18nDir);
        if ($real === false || $rootReal === false
            || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("i18n file not found or out of root: {$lang}");
        }
        $raw = @file_get_contents($real);
        if ($raw === false) {
            throw new \RuntimeException("i18n file not readable: {$lang}");
        }
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON in i18n file: {$lang} ({$e->getMessage()})", 0, $e);
        }
        $langRoot = $singleLang ? $basePath : ($basePath . '/' . $lang);
        self::resolvePaths($data, $langRoot, $basePath);

        // Editor-managed overlay — optional layer merged on top of
        // the theme-provided base dictionary. Gated by setOverlayDir
        // (the content directory) AND by an allowlist of top-level
        // sections from `config/i18n.php → i18n_overlay.allowed_sections`.
        // The allowlist is the security boundary: anything the editor
        // writes outside those sections is silently dropped by the
        // overlay loader, so the editor can never overwrite chrome
        // strings, error messages, or any other developer-controlled
        // surface even by trying. Path markers in overlay values are
        // resolved with the same rules as the base, so `~/about` in
        // overlay JSON behaves identically to `~/about` in the
        // theme's en.json.
        if (self::$overlayDir !== null) {
            $allowed = (array)Config::get(
                'i18n_overlay.allowed_sections',
                ['nav', 'footer']
            );
            $overlay = I18nOverlay::load($lang, self::$overlayDir, $allowed);
            if ($overlay !== null) {
                self::resolvePaths($overlay, $langRoot, $basePath);
                $data = I18nOverlay::merge($data, $overlay);
            }
        }

        return self::$loaded[$cacheKey] = $data;
    }

    /**
     * Recursively rewrite path-marker prefixes in string values. Two
     * markers are honoured; everything else is left as-is.
     *
     *   "~/foo"  →  "<langRoot>/foo"     — language-aware page link
     *               (e.g. /site/de/foo or just /foo for single-lang)
     *
     *   "^/foo"  →  "<basePath>/foo"     — language-independent path,
     *               typically an asset URL like ^/assets/photos/x.jpg
     *
     * Any string that doesn't begin with one of these markers is left
     * untouched, so body text starting with "~" or "^" would survive
     * (uncommon, but worth noting). Markers must be the first character
     * of the value.
     *
     * @param mixed $node
     */
    private static function resolvePaths(mixed &$node, string $langRoot, string $basePath): void
    {
        if (is_array($node)) {
            foreach ($node as &$v) {
                self::resolvePaths($v, $langRoot, $basePath);
            }
            unset($v);
            return;
        }
        if (!is_string($node) || $node === '') {
            return;
        }
        if ($node[0] === '~') {
            $node = $langRoot . substr($node, 1);
        } elseif ($node[0] === '^') {
            $node = $basePath . substr($node, 1);
        }
    }
}
