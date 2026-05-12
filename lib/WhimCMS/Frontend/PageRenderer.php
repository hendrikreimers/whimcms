<?php
declare(strict_types=1);

namespace H42\WhimCMS\Frontend;

use H42\WhimCMS\Config;
use H42\WhimCMS\Content\ContentNotFoundException;
use H42\WhimCMS\Content\PageLoader;
use H42\WhimCMS\I18n;
use H42\WhimCMS\Log;
use H42\WhimCMS\Security\Http\RequestSecurity;
use H42\WhimCMS\Template\Engine;

/**
 * Renders a page (matched route or 404) to the response stream.
 *
 * The Kernel late-constructs one PageRenderer per request, after the
 * dispatcher has resolved the base path. The renderer then has all
 * the request-bound state it needs to produce HTML — it does not
 * touch `$_SERVER` directly except for cache-key / form-state hints
 * (`?sent=1`).
 *
 * Two surface methods:
 *   - `render()`         — happy path: a route resolved, optionally with
 *                          form re-render state from a failed POST.
 *   - `renderNotFound()` — the route did not resolve; pick the language
 *                          from the URL prefix (or fall back to
 *                          Accept-Language) and emit the layout's
 *                          built-in 404 page.
 *
 * Layout-name resolution:
 *   - `default` → `layout`
 *   - `<other>` → `layout-<other>`
 *   With a defence-in-depth check against the configured allowlist —
 *   the PageLoader has already verified this against the same list,
 *   but the recheck guards against any future code path that bypasses
 *   the loader.
 *
 * Meta merging:
 *   Front-matter takes precedence; missing fields fall back to the
 *   i18n `meta.<slug>` map. A page with only a title in front-matter
 *   still picks up the existing i18n description, and vice versa, so
 *   partial migrations don't blank out fields.
 */
final class PageRenderer
{
    /**
     * @param list<string>                          $supportedLangs
     * @param array<string, array<string, string>>  $routes
     * @param list<string>                          $allowedLayouts
     */
    public function __construct(
        private Engine $engine,
        private PageLoader $pageLoader,
        private LanguageDetector $languageDetector,
        private string $basePath,
        private array $supportedLangs,
        private array $routes,
        private bool $singleLang,
        private array $allowedLayouts,
        private string $stateDir,
        private string $themeUrl,
        private bool $debug,
    ) {
    }

    /**
     * Render the page matched by the dispatcher.
     *
     * @param array<string, mixed>      $resolved   {lang, slug, …} from Router
     * @param array<string, mixed>|null $formState  Result of a failed contact-form
     *                                              POST, drives 422 + error/values
     *                                              re-render. Null on the happy path.
     * @param bool                      $formSent   True after a successful contact
     *                                              POST redirect (`?sent=1`).
     */
    public function render(array $resolved, ?array $formState = null, bool $formSent = false): void
    {
        $lang = $resolved['lang'];
        $slug = $resolved['slug'];
        $dict = I18n::load($lang, $this->basePath, $this->singleLang);

        // Try the block-based content path first. A missing .md falls back
        // to the legacy per-page template so unmigrated pages keep working
        // exactly as before.
        $page = null;
        try {
            $page = $this->pageLoader->load($lang, $slug, $this->basePath, $this->singleLang);
        } catch (ContentNotFoundException) {
            $page = null;
        }

        // Hard-disabled pages are delivered as 404. The .md still
        // exists on disk and the route still resolves — only the
        // visitor-facing surface is gated. Operator restores the
        // page by clearing the `disabled: true` flag in the
        // front-matter.
        if ($page !== null && $page->disabled()) {
            $segment = (string)($resolved['segment'] ?? '');
            $this->renderNotFound($this->singleLang ? $segment : ($lang . '/' . $segment));
            return;
        }

        $meta = $page !== null
            ? $this->mergeMeta($page->meta(), $dict['meta'][$slug] ?? [])
            : ($dict['meta'][$slug] ?? ['title' => (string)Config::get('seo.site_name', ''), 'description' => '']);

        $layoutName = $this->layoutTemplateName($page?->layout() ?? 'default');

        $context = RenderContext::build(
            dict:           $dict,
            lang:           $lang,
            slug:           $slug,
            basePath:       $this->basePath,
            meta:           $meta,
            pageTemplate:   'pages/' . $slug,
            supportedLangs: $this->supportedLangs,
            routes:         $this->routes,
            singleLang:     $this->singleLang,
            stateDir:       $this->stateDir,
            blocks:         $page?->blocks,
            formState:      $formState,
            formSent:       $formSent || (isset($_GET['sent']) && $_GET['sent'] === '1'),
            csrfBindKey:    RequestSecurity::clientBindKey(),
            themeUrl:       $this->themeUrl,
        );

        if (!headers_sent()) {
            if ($formState !== null) {
                http_response_code(422); // validation failed → don't cache
            }
            header('Content-Type: text/html; charset=utf-8');
            // Debug-only diagnostic so a `curl -I` immediately reveals
            // whether the page came from the content cache. Production
            // (debug=false) suppresses the header entirely — no
            // information about render path leaks to attackers. Header
            // name uses the H42 prefix, consistent with X-H42-Error and
            // the project's identity-obfuscation convention.
            if ($this->debug) {
                $cacheStatus = $page !== null
                    ? ($this->pageLoader->lastStatus() ?? 'unknown')
                    : 'no-content';
                header('X-H42-Cache: ' . $cacheStatus);
            }
        }
        echo $this->engine->render($layoutName, $context);
    }

    /**
     * Render the 404 page.
     *
     * `$path` is the dispatcher-stripped request path (after BASE), used
     * only for picking the language (URL prefix preferred) and for the
     * access log. The 404 page itself is the layout template's built-in
     * error region — no separate `_404.html` is required.
     */
    public function renderNotFound(string $path): void
    {
        Log::info('404', ['path' => $path]);
        $lang = $this->languageDetector->forPath($path);
        $dict = I18n::load($lang, $this->basePath, $this->singleLang);

        $context = RenderContext::build(
            dict:           $dict,
            lang:           $lang,
            slug:           'home',
            basePath:       $this->basePath,
            meta:           $dict['errors']['_404'] ?? ['title' => 'Not found', 'description' => ''],
            pageTemplate:   'pages/_404',
            supportedLangs: $this->supportedLangs,
            routes:         $this->routes,
            singleLang:     $this->singleLang,
            stateDir:       $this->stateDir,
            csrfBindKey:    RequestSecurity::clientBindKey(),
            themeUrl:       $this->themeUrl,
        );
        $context['PAGE'] = '_error';

        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $this->engine->render('layout', $context);
    }

    /**
     * Map a layout name from the page front-matter to a template file.
     * The name has already been validated against the allowlist by
     * PageLoader; the recheck here is defence-in-depth in case a new
     * code path ever skips the loader.
     *
     *   'default' → 'layout'
     *   '<other>' → 'layout-<other>'
     */
    private function layoutTemplateName(string $layout): string
    {
        if (!in_array($layout, $this->allowedLayouts, true)) {
            $layout = 'default';
        }
        return $layout === 'default' ? 'layout' : ('layout-' . $layout);
    }

    /**
     * Merge meta from front-matter (preferred) with i18n (fallback). A
     * page that has only `title` in its front-matter still picks up
     * the existing i18n description, and vice versa, so partial
     * migrations don't blank out fields.
     *
     * @param array<string, string> $fromPage
     * @param array<string, mixed>  $fromI18n
     * @return array<string, string>
     */
    private function mergeMeta(array $fromPage, array $fromI18n): array
    {
        $title = $fromPage['title'] ?? '';
        if ($title === '' && is_string($fromI18n['title'] ?? null)) {
            $title = (string)$fromI18n['title'];
        }
        $desc = $fromPage['description'] ?? '';
        if ($desc === '' && is_string($fromI18n['description'] ?? null)) {
            $desc = (string)$fromI18n['description'];
        }
        return ['title' => $title, 'description' => $desc];
    }
}
