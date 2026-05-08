<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;
use H42\WhimCMS\Router;

/**
 * Builds the per-page SEO sub-context: canonical URL, hreflang
 * alternates, Open Graph / Twitter values, robots flag, and the
 * JSON-LD blob with Person + Organization structured data.
 *
 * Pulled out of RenderContext so SEO concerns live alongside the
 * other Seo\* helpers (Origin, Robots, Sitemap). The result is the
 * value the layout template renders under the `SEO` context key.
 */
final class PageSeo
{
    /**
     * @param array<int, string>                    $supportedLangs
     * @param array<string, array<string, string>>  $routes
     * @param array<string, mixed>                  $meta
     * @return array<string, mixed>
     */
    public static function build(
        string $slug,
        string $lang,
        array $supportedLangs,
        array $routes,
        string $basePath,
        bool $singleLang,
        array $meta,
    ): array {
        $origin = Origin::resolve();
        $langRoutes = $routes[$lang] ?? [];
        $canonical  = $origin . Router::canonicalUrl($slug, $lang, $langRoutes, $basePath, $singleLang);

        $alternates = [];
        $xDefault   = $canonical;
        $defaultLang = (string)Config::get('default_lang', 'en');
        if (!$singleLang) {
            foreach ($supportedLangs as $code) {
                $cr = $routes[$code] ?? [];
                if (Router::segmentForSlug($cr, $slug) === null) {
                    continue;
                }
                $url = $origin . Router::canonicalUrl($slug, $code, $cr, $basePath, $singleLang);
                $alternates[] = ['lang' => $code, 'url' => $url];
                if ($code === $defaultLang) {
                    $xDefault = $url;
                }
            }
        }

        $ogImage = (string)Config::get('seo.og_image', '');
        $ogImageUrl = $ogImage === ''
            ? ''
            : ($origin . $basePath . (str_starts_with($ogImage, '/') ? $ogImage : '/' . $ogImage));

        return [
            'canonical'     => $canonical,
            'alternates'    => $alternates,
            'xDefault'      => $xDefault,
            'indexable'     => (bool)Config::get('seo.indexable', false),
            'siteName'      => (string)Config::get('seo.site_name', ''),
            'ogImage'       => $ogImageUrl,
            'ogLocale'      => self::ogLocaleFor($lang),
            'twitterHandle' => (string)Config::get('seo.twitter_handle', ''),
            'ldJson'        => self::buildLdJson($origin, $basePath, $meta, $ogImageUrl),
        ];
    }

    /**
     * Map an ISO-639-1 language to an Open Graph locale code. Defaults
     * to plain `<lang>` for any code we don't have a specific mapping
     * for.
     */
    private static function ogLocaleFor(string $lang): string
    {
        return match ($lang) {
            'en' => 'en_US',
            'de' => 'de_DE',
            'ro' => 'ro_RO',
            default => $lang,
        };
    }

    /**
     * Compose the JSON-LD structured-data block. Encoded with JSON_HEX_*
     * so the resulting string contains none of `<>&'"` literally — safe
     * to drop into a `<script type="application/ld+json">` element via
     * the engine's raw-output mode without further escaping breaking
     * the JSON syntax.
     *
     * @param array<string, mixed> $meta
     */
    private static function buildLdJson(string $origin, string $basePath, array $meta, string $ogImageUrl): string
    {
        $org    = (array)Config::get('seo.organization', []);
        $person = (array)Config::get('seo.person', []);

        $orgImage    = (string)($org['logo'] ?? '');
        $orgImageUrl = $orgImage === ''
            ? null
            : ($origin . $basePath . (str_starts_with($orgImage, '/') ? $orgImage : '/' . $orgImage));

        $personImage    = (string)($person['image'] ?? '');
        $personImageUrl = $personImage === ''
            ? null
            : ($origin . $basePath . (str_starts_with($personImage, '/') ? $personImage : '/' . $personImage));

        $graph = [];

        $personNode = array_filter([
            '@type'    => 'Person',
            'name'     => (string)($person['name'] ?? ''),
            'jobTitle' => (string)($person['jobTitle'] ?? ''),
            'image'    => $personImageUrl,
            'url'      => $origin . $basePath . '/',
        ], static fn($v) => $v !== '' && $v !== null);
        if (!empty($personNode['name'])) {
            $graph[] = $personNode;
        }

        $orgNode = array_filter([
            '@type'  => 'Organization',
            'name'   => (string)($org['name'] ?? ''),
            'logo'   => $orgImageUrl,
            'url'    => $origin . $basePath . '/',
            'sameAs' => array_values(array_filter((array)($org['sameAs'] ?? []))),
        ], static fn($v) => $v !== '' && $v !== null && $v !== []);
        if (!empty($orgNode['name'])) {
            $graph[] = $orgNode;
        }

        if ($graph === []) {
            return '{}';
        }

        $payload = ['@context' => 'https://schema.org', '@graph' => $graph];

        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return is_string($json) ? $json : '{}';
    }
}
