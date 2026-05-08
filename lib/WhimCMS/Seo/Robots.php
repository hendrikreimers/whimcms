<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;

/**
 * Dynamic /robots.txt response.
 *
 * Driven by `seo.indexable`:
 *   - false → disallow everything (pre-launch, staging)
 *   - true  → allow all + advertise the sitemap
 *
 * Always served as text/plain.
 */
final class Robots
{
    public static function send(string $basePath): void
    {
        $indexable = (bool)Config::get('seo.indexable', false);
        $sitemapUrl = Origin::resolve() . $basePath . '/sitemap.xml';

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
        }

        if (!$indexable) {
            echo "User-agent: *\n";
            echo "Disallow: /\n";
            return;
        }

        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "\n";
        echo "Sitemap: {$sitemapUrl}\n";
    }
}
