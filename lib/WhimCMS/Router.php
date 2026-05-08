<?php
declare(strict_types=1);

namespace H42\WhimCMS;

/**
 * URL ↔ page-slug routing.
 *
 * Three concerns:
 *   1. Detect the deployment base path from SCRIPT_NAME so the same
 *      codebase works at the document root or in a subfolder.
 *   2. Parse a request URL into (lang, segment, slug) using the
 *      per-language routes table from config.
 *   3. Build canonical URLs and the per-language link set used by the
 *      language switcher.
 *
 * Route table shape (from config):
 *   [
 *     'en' => ['' => 'home', 'students' => 'students', …],
 *     'de' => ['' => 'home', 'schueler' => 'students', …],
 *     'ro' => ['' => 'home', 'elevi'    => 'students', …],
 *   ]
 *
 * Single-language mode (only one entry in supported_langs) drops the
 * /<lang>/ prefix from URLs entirely.
 */
final class Router
{
    /**
     * Detect the base path from SCRIPT_NAME.
     *   /index.php      → ""
     *   /site/index.php → "/site"
     */
    public static function detectBasePath(string $scriptName): string
    {
        $scriptName = self::stripUnsafe($scriptName);
        $dir = str_replace('\\', '/', dirname($scriptName));
        $dir = rtrim($dir, '/');
        return $dir === '.' ? '' : $dir;
    }

    /**
     * Strip the base path and the leading/trailing slashes from a raw
     * REQUEST_URI. Also tolerates legacy ".html" suffixes for one-time
     * redirects from the previous URL scheme.
     */
    public static function stripBase(string $rawUri, string $basePath): string
    {
        $rawUri = self::stripUnsafe($rawUri);
        $path = parse_url($rawUri, PHP_URL_PATH) ?? '';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        return trim($path, '/');
    }

    /**
     * Resolve a stripped path into (lang, segment, slug, legacyHtml).
     * Returns null when no canonical resolution is possible.
     *
     * Single-language deployments don't expect a lang prefix; the lang
     * is always the only supported one.
     *
     * @param array<int, string>                   $supportedLangs
     * @param array<string, array<string, string>> $routes
     * @return array{lang: string, segment: string, slug: string, legacyHtml: bool}|null
     */
    public static function resolvePath(string $path, array $supportedLangs, array $routes): ?array
    {
        $legacyHtml = false;
        if (str_ends_with($path, '.html')) {
            $legacyHtml = true;
            $path = substr($path, 0, -5);
        }

        $singleLang = count($supportedLangs) === 1;

        if ($singleLang) {
            $lang = $supportedLangs[0];
            $segment = $path;
            $slug = $routes[$lang][$segment] ?? null;
            if ($slug === null) {
                return null;
            }
            return ['lang' => $lang, 'segment' => $segment, 'slug' => $slug, 'legacyHtml' => $legacyHtml];
        }

        if ($path === '') {
            // No language prefix at all — caller is expected to redirect
            // to a detected language. Return null so the front controller
            // can take that branch explicitly.
            return null;
        }

        $parts = explode('/', $path, 2);
        $lang  = $parts[0];
        $rest  = $parts[1] ?? '';

        if (!in_array($lang, $supportedLangs, true)) {
            return null;
        }

        $slug = $routes[$lang][$rest] ?? null;
        if ($slug === null) {
            return null;
        }
        return ['lang' => $lang, 'segment' => $rest, 'slug' => $slug, 'legacyHtml' => $legacyHtml];
    }

    /**
     * Reverse-lookup: given a canonical slug and a language, return the
     * URL segment used in that language. Returns null if the slug isn't
     * known in that language.
     *
     * @param array<string, string> $langRoutes
     */
    public static function segmentForSlug(array $langRoutes, string $slug): ?string
    {
        $segment = array_search($slug, $langRoutes, true);
        return $segment === false ? null : (string)$segment;
    }

    /**
     * Build the canonical URL for (slug, lang) under the deployment base.
     *
     * @param array<string, string> $langRoutes
     */
    public static function canonicalUrl(string $slug, string $lang, array $langRoutes, string $basePath, bool $singleLang): string
    {
        $segment = self::segmentForSlug($langRoutes, $slug);
        if ($segment === null) {
            // Fall back to "/" when the slug isn't routable in this lang.
            $segment = '';
        }
        if ($singleLang) {
            return $basePath . '/' . $segment;
        }
        $tail = $segment === '' ? '/' : '/' . $segment;
        return $basePath . '/' . $lang . $tail;
    }

    /**
     * Build the language switcher list for the current page: one entry
     * per supported language, with `code`, `url`, and `active`.
     *
     * @param array<int, string>                   $supportedLangs
     * @param array<string, array<string, string>> $routes
     * @return list<array{code: string, url: string, active: bool}>
     */
    public static function buildLangSwitch(string $slug, string $currentLang, array $supportedLangs, array $routes, string $basePath): array
    {
        if (count($supportedLangs) <= 1) {
            return [];
        }
        $out = [];
        foreach ($supportedLangs as $code) {
            $langRoutes = $routes[$code] ?? null;
            if (!is_array($langRoutes)) {
                continue;
            }
            if (self::segmentForSlug($langRoutes, $slug) === null) {
                // Page isn't published in this language — skip rather
                // than render a dead link.
                continue;
            }
            $out[] = [
                'code'   => $code,
                'url'    => self::canonicalUrl($slug, $code, $langRoutes, $basePath, false),
                'active' => $code === $currentLang,
            ];
        }
        return $out;
    }

    /**
     * Defence-in-depth: scrub null bytes and control characters before
     * any path inspection. A REQUEST_URI containing %00 or stray \r\n
     * usually means an attacker is probing.
     */
    private static function stripUnsafe(string $value): string
    {
        if ($value === '' || strpbrk($value, "\0\r\n") === false) {
            return $value;
        }
        return str_replace(["\0", "\r", "\n"], '', $value);
    }
}
