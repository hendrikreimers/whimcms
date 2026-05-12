<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;
use H42\WhimCMS\Content\ContentNotFoundException;
use H42\WhimCMS\Content\PageLoader;
use H42\WhimCMS\Router;

/**
 * Dynamic /sitemap.xml response.
 *
 * Per-language gating: a page is included in the sitemap only when
 * its own front-matter for that language passes the visibility
 * check (no `hidden`, no `disabled`). Setting `disabled: true` in
 * `en` does NOT remove the German version — each language stands
 * on its own. Removing a page from the sitemap entirely requires
 * setting the flag in every language it is published in.
 *
 * Operator-side override: `seo.sitemap_exclude` in config still
 * skips a slug across all languages — coarser knob, useful for
 * pages whose front-matter you don't want to touch.
 *
 * Per-page parse failures are swallowed: a single corrupt .md
 * should not take down the sitemap. The affected (slug, lang)
 * combination is skipped; others continue.
 */
final class Sitemap
{
    public static function send(string $basePath, PageLoader $pageLoader, bool $singleLang): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
        }

        /** @var array<int, string> $supportedLangs */
        $supportedLangs = (array)Config::get('supported_langs', ['en']);
        /** @var array<string, array<string, string>> $routes */
        $routes = (array)Config::get('routes', []);
        /** @var array<int, string> $exclude */
        $exclude = (array)Config::get('seo.sitemap_exclude', []);
        $defaultLang = (string)Config::get('default_lang', $supportedLangs[0] ?? 'en');
        $origin = Origin::resolve();

        // Visibility map: (slug, lang) → bool. true = sitemap-eligible
        // for that language. Computed once per request from the live
        // page state so the iteration below stays cheap.
        $eligible = self::buildEligibilityMap(
            $supportedLangs, $routes, $exclude, $pageLoader, $basePath, $singleLang
        );

        // Slug iteration order: every slug routed in ANY language,
        // de-duplicated, preserving the order of the default-lang
        // route map so the sitemap looks stable for operators.
        $slugs = self::collectSlugs($supportedLangs, $routes, $defaultLang);

        $now = date('Y-m-d');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        echo ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($slugs as $slug) {
            $activeLangs = [];
            foreach ($supportedLangs as $lang) {
                if (!is_string($lang)) continue;
                if (!empty($eligible[$slug][$lang] ?? false)) {
                    $activeLangs[] = $lang;
                }
            }
            if ($activeLangs === []) {
                // Every language version is hidden/disabled/missing →
                // page is fully off the sitemap.
                continue;
            }

            // Primary language for the <loc>: prefer the default
            // language if it's still eligible, otherwise the first
            // surviving language in supported-order. This keeps the
            // canonical URL meaningful even when the default lang
            // hides the page.
            $primary = in_array($defaultLang, $activeLangs, true)
                ? $defaultLang
                : $activeLangs[0];

            $primaryRoutes = $routes[$primary] ?? [];
            $primaryUrl = $origin . Router::canonicalUrl(
                $slug,
                $primary,
                $primaryRoutes,
                $basePath,
                $singleLang
            );

            echo "  <url>\n";
            echo "    <loc>" . self::xml($primaryUrl) . "</loc>\n";
            echo "    <lastmod>{$now}</lastmod>\n";
            foreach ($activeLangs as $lang) {
                $langRoutes = $routes[$lang] ?? [];
                $url = $origin . Router::canonicalUrl($slug, $lang, $langRoutes, $basePath, $singleLang);
                echo '    <xhtml:link rel="alternate" hreflang="' . self::xml($lang)
                    . '" href="' . self::xml($url) . "\" />\n";
            }
            echo '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                . self::xml($primaryUrl) . "\" />\n";
            echo "  </url>\n";
        }
        echo "</urlset>\n";
    }

    /**
     * Build the (slug, lang) → bool eligibility map. Eligible when:
     *
     *   - the lang has a route for the slug, AND
     *   - the slug is not on `seo.sitemap_exclude` (coarse operator
     *     allowlist), AND
     *   - the page's front-matter for that lang has neither
     *     `hidden: true` nor `disabled: true`.
     *
     * Pages that don't exist on disk are eligible iff the route
     * exists — pre-flag behaviour for legacy / template-page
     * slugs that have no .md.
     *
     * @param array<int, string>                   $supportedLangs
     * @param array<string, array<string, string>> $routes
     * @param array<int, string>                   $exclude
     * @return array<string, array<string, bool>>
     */
    private static function buildEligibilityMap(
        array $supportedLangs,
        array $routes,
        array $exclude,
        PageLoader $pageLoader,
        string $basePath,
        bool $singleLang,
    ): array {
        $out = [];
        foreach ($supportedLangs as $lang) {
            if (!is_string($lang)) continue;
            $langRoutes = $routes[$lang] ?? [];
            if (!is_array($langRoutes)) continue;
            foreach ($langRoutes as $slug) {
                if (!is_string($slug)) continue;
                if (in_array($slug, $exclude, true)) {
                    $out[$slug][$lang] = false;
                    continue;
                }
                try {
                    $page = $pageLoader->load($lang, $slug, $basePath, $singleLang);
                } catch (ContentNotFoundException) {
                    // No .md for this (slug, lang). Treat as
                    // eligible — same as pre-flag behaviour for
                    // routed pages without content (legacy
                    // template-only pages).
                    $out[$slug][$lang] = true;
                    continue;
                } catch (\Throwable) {
                    // Parse / runtime error → skip conservatively.
                    $out[$slug][$lang] = false;
                    continue;
                }
                $out[$slug][$lang] = !$page->hidden() && !$page->disabled();
            }
        }
        return $out;
    }

    /**
     * Slug iteration order. Default-lang routes come first (preserves
     * the operator's curated order); other langs append any slugs
     * only published there.
     *
     * @param array<int, string>                   $supportedLangs
     * @param array<string, array<string, string>> $routes
     * @return list<string>
     */
    private static function collectSlugs(array $supportedLangs, array $routes, string $defaultLang): array
    {
        $out = [];
        $seen = [];
        $defaultLangRoutes = $routes[$defaultLang] ?? [];
        if (is_array($defaultLangRoutes)) {
            foreach ($defaultLangRoutes as $slug) {
                if (is_string($slug) && !isset($seen[$slug])) {
                    $out[] = $slug;
                    $seen[$slug] = true;
                }
            }
        }
        foreach ($supportedLangs as $lang) {
            if (!is_string($lang) || $lang === $defaultLang) continue;
            $langRoutes = $routes[$lang] ?? [];
            if (!is_array($langRoutes)) continue;
            foreach ($langRoutes as $slug) {
                if (is_string($slug) && !isset($seen[$slug])) {
                    $out[] = $slug;
                    $seen[$slug] = true;
                }
            }
        }
        return $out;
    }

    /** XML-escape a value safe for element text or attribute. */
    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
