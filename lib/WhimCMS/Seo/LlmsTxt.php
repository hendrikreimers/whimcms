<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;
use H42\WhimCMS\Content\ContentNotFoundException;
use H42\WhimCMS\Content\Page;
use H42\WhimCMS\Content\PageLoader;
use H42\WhimCMS\I18n;
use H42\WhimCMS\Router;

/**
 * Dynamic /llms.txt response (format: https://llmstxt.org).
 *
 * One file per language, mirroring the language-split routing:
 *   /llms.txt        → default language (or the only language)
 *   /<lang>/llms.txt → that language
 *
 * The Kernel gates the endpoint on BOTH `seo.llms.enabled` and
 * `seo.indexable` before this class is reached, so a non-indexable
 * (pre-launch / staging) site never advertises its page list — see
 * Kernel::dispatch / Kernel::matchLlmsTxtLang.
 *
 * Page selection (per language, in routes.php order):
 *   - skip slugs on `seo.sitemap_exclude` (coarse operator allowlist —
 *     "not in sitemap.xml ⇒ not in llms.txt")
 *   - skip pages flagged `hidden` or `disabled` in their front-matter
 *     (the same visibility gate sitemap.xml applies)
 *   - skip pages flagged `llms: exclude` (llms-only opt-out; the page
 *     stays in sitemap.xml)
 *   - `llms: feature` pins the page to the top of the main list
 *   - `llms: optional` moves the page into the "## Optional" section
 *   - everything else is listed normally under "## Pages"
 *
 * Title / description resolution mirrors PageRenderer's meta merge:
 *   title       front-matter meta.title → i18n meta.<slug>.title → slug
 *   description front-matter meta.description → i18n meta.<slug>.description → ""
 * An empty description simply omits the trailing ": …" — descriptions
 * are never auto-summarised.
 *
 * The summary line ("> …") reuses the home page's description; it is
 * omitted entirely when that description is empty.
 *
 * Output safety: every author-controlled string (site name, summary,
 * titles, descriptions) is collapsed to a single line — control
 * characters and newlines are stripped — so a stray newline in a
 * description cannot inject a fake list entry into the line-based
 * format. Square brackets in titles are neutralised so they cannot
 * prematurely close the Markdown link text.
 *
 * Per-page parse failures are swallowed: one corrupt .md skips that
 * (slug, lang) entry rather than taking down the whole file — the same
 * posture as Sitemap.
 */
final class LlmsTxt
{
    public static function send(string $basePath, PageLoader $pageLoader, bool $singleLang, string $lang): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
        }

        $siteName = self::oneLine((string)Config::get('seo.site_name', ''));
        /** @var array<string, array<string, string>> $routes */
        $routes  = (array)Config::get('routes', []);
        /** @var array<int, string> $exclude */
        $exclude = (array)Config::get('seo.sitemap_exclude', []);
        $origin  = Origin::resolve();

        $langRoutes = $routes[$lang] ?? [];
        if (!is_array($langRoutes)) {
            $langRoutes = [];
        }

        // i18n dictionary drives the meta fallback chain (front-matter
        // wins, then meta.<slug>). A failure here is non-fatal — the
        // chain just falls back to the slug / empty description.
        $i18nMeta = [];
        try {
            $dict = I18n::load($lang, $basePath, $singleLang);
            if (is_array($dict['meta'] ?? null)) {
                $i18nMeta = $dict['meta'];
            }
        } catch (\Throwable) {
            $i18nMeta = [];
        }

        // Three buckets, each preserving routes.php order.
        $feature  = [];
        $pages    = [];
        $optional = [];

        foreach ($langRoutes as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            if (in_array($slug, $exclude, true)) {
                continue;
            }

            $page = null;
            try {
                $page = $pageLoader->load($lang, $slug, $basePath, $singleLang);
            } catch (ContentNotFoundException) {
                // Routed page without a .md (legacy template page) —
                // still eligible, exactly like sitemap.xml.
                $page = null;
            } catch (\Throwable) {
                // Corrupt / unparseable page → skip conservatively.
                continue;
            }

            if ($page !== null && ($page->hidden() || $page->disabled())) {
                continue;
            }

            $mode = $page !== null ? $page->llms() : '';
            if ($mode === 'exclude') {
                continue;
            }

            $url   = $origin . Router::canonicalUrl($slug, $lang, $langRoutes, $basePath, $singleLang);
            $entry = self::formatEntry(
                self::resolveTitle($page, $i18nMeta, $slug),
                $url,
                self::resolveDescription($page, $i18nMeta, $slug)
            );

            if ($mode === 'feature') {
                $feature[] = $entry;
            } elseif ($mode === 'optional') {
                $optional[] = $entry;
            } else {
                $pages[] = $entry;
            }
        }

        echo '# ' . ($siteName !== '' ? $siteName : 'WhimCMS') . "\n";

        $summary = self::resolveSummary($langRoutes, $i18nMeta, $lang, $pageLoader, $basePath, $singleLang);
        if ($summary !== '') {
            echo '> ' . $summary . "\n";
        }

        $main = array_merge($feature, $pages);
        if ($main !== []) {
            echo "\n## Pages\n";
            foreach ($main as $line) {
                echo $line . "\n";
            }
        }
        if ($optional !== []) {
            echo "\n## Optional\n";
            foreach ($optional as $line) {
                echo $line . "\n";
            }
        }
    }

    /**
     * Site summary line: the home page's description, run through the
     * same fallback chain as any other page. Empty when home has no
     * description (the caller then omits the "> …" line).
     *
     * @param array<string, string> $langRoutes
     * @param array<string, mixed>  $i18nMeta
     */
    private static function resolveSummary(
        array $langRoutes,
        array $i18nMeta,
        string $lang,
        PageLoader $pageLoader,
        string $basePath,
        bool $singleLang
    ): string {
        $homeSlug = $langRoutes[''] ?? null;
        if (!is_string($homeSlug)) {
            return '';
        }
        $page = null;
        try {
            $page = $pageLoader->load($lang, $homeSlug, $basePath, $singleLang);
        } catch (\Throwable) {
            $page = null;
        }
        return self::resolveDescription($page, $i18nMeta, $homeSlug);
    }

    /**
     * @param array<string, mixed> $i18nMeta
     */
    private static function resolveTitle(?Page $page, array $i18nMeta, string $slug): string
    {
        $title = $page !== null ? ($page->meta()['title'] ?? '') : '';
        if ($title === '') {
            $m = $i18nMeta[$slug] ?? null;
            if (is_array($m) && is_string($m['title'] ?? null)) {
                $title = $m['title'];
            }
        }
        if ($title === '') {
            $title = $slug;
        }
        return self::linkText($title);
    }

    /**
     * @param array<string, mixed> $i18nMeta
     */
    private static function resolveDescription(?Page $page, array $i18nMeta, string $slug): string
    {
        $desc = $page !== null ? ($page->meta()['description'] ?? '') : '';
        if ($desc === '') {
            $m = $i18nMeta[$slug] ?? null;
            if (is_array($m) && is_string($m['description'] ?? null)) {
                $desc = $m['description'];
            }
        }
        return self::oneLine($desc);
    }

    private static function formatEntry(string $title, string $url, string $description): string
    {
        $line = '- [' . $title . '](' . $url . ')';
        if ($description !== '') {
            $line .= ': ' . $description;
        }
        return $line;
    }

    /**
     * Collapse a value to a single safe line: strip control characters
     * (incl. CR/LF, which would otherwise let author content inject
     * extra lines into the format), collapse whitespace runs, trim.
     */
    private static function oneLine(string $s): string
    {
        $s = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
        $s = (string)preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * oneLine() plus neutralising the square brackets that would
     * prematurely close / corrupt the Markdown link text "[…]".
     */
    private static function linkText(string $s): string
    {
        return self::oneLine(str_replace(['[', ']'], ['(', ')'], $s));
    }
}
