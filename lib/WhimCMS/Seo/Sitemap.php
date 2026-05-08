<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;
use H42\WhimCMS\Router;

/**
 * Dynamic /sitemap.xml response.
 *
 * Walks the configured routes and emits one <url> entry per canonical
 * page, with <xhtml:link rel="alternate" hreflang="…"> entries for every
 * language version. Pages listed in `seo.sitemap_exclude` are skipped
 * (use this for landing pages you don't want indexed).
 *
 * URLs are absolute, anchored at Origin::resolve() — point your search
 * console at this endpoint after launch.
 */
final class Sitemap
{
    public static function send(string $basePath): void
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
        $singleLang = count($supportedLangs) === 1;
        $defaultLang = (string)Config::get('default_lang', 'en');
        $origin = Origin::resolve();

        // Collect every canonical slug that has a route in the default
        // language. We dedupe so a slug repeated across langs doesn't
        // produce duplicate <url> entries.
        $defaultLangRoutes = $routes[$defaultLang] ?? [];
        $slugs = [];
        foreach ($defaultLangRoutes as $slug) {
            if (!in_array($slug, $slugs, true) && !in_array($slug, $exclude, true)) {
                $slugs[] = $slug;
            }
        }

        $now = date('Y-m-d');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        echo ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($slugs as $slug) {
            $defaultUrl = $origin . Router::canonicalUrl(
                $slug,
                $defaultLang,
                $defaultLangRoutes,
                $basePath,
                $singleLang
            );
            echo "  <url>\n";
            echo "    <loc>" . self::xml($defaultUrl) . "</loc>\n";
            echo "    <lastmod>{$now}</lastmod>\n";
            // hreflang alternates — one entry per language that has the
            // slug in its routes. Skip langs missing the slug entirely.
            foreach ($supportedLangs as $lang) {
                $langRoutes = $routes[$lang] ?? [];
                if (Router::segmentForSlug($langRoutes, $slug) === null) {
                    continue;
                }
                $url = $origin . Router::canonicalUrl($slug, $lang, $langRoutes, $basePath, $singleLang);
                echo '    <xhtml:link rel="alternate" hreflang="' . self::xml($lang) . '" href="' . self::xml($url) . "\" />\n";
            }
            // x-default points at the default-language version.
            echo '    <xhtml:link rel="alternate" hreflang="x-default" href="' . self::xml($defaultUrl) . "\" />\n";
            echo "  </url>\n";
        }
        echo "</urlset>\n";
    }

    /** XML-escape a value safe for element text or attribute. */
    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
