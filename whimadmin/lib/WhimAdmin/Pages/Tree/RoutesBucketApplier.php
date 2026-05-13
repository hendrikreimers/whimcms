<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Pages\OverlayWriter;
use H42\WhimAdmin\Pages\RoutesUpdater;

/**
 * Apply the `routes` bucket of a save payload to one tree node.
 *
 * Bucket shape (a sub-set of these keys may be present):
 *
 *     [
 *       'slug' => 'foo',          // optional new slug — triggers rename
 *       'url'  => 'demos/foo',    // optional new URL  — triggers changeUrl
 *     ]
 *
 * Two side-effects of `apply()`:
 *
 *   1. SlugRenamer is invoked when the bucket's `slug` differs from
 *      the current slug. Records rollback hooks on $log.
 *
 *   2. Routes table is mutated when the bucket's `url` differs from
 *      the current URL — with a smart-default: when a slug rename
 *      happens AND the user didn't manually touch the URL field AND
 *      the pre-rename URL's last segment was exactly the old slug,
 *      the URL's last segment is auto-rewritten to match the new
 *      slug. Mirrors `create()`'s default `composeUrl(prefix, slug)`
 *      so a routed page whose URL still reflects its slug stays in
 *      sync through a rename.
 *
 * Routes are committed at the end of `apply()` so a single save with
 * both slug and URL changes lands on disk as one atomic write.
 *
 * Returns a RouteResolution carrying the post-mutation slug and the
 * URL-changed flag, which the caller uses to trigger the URL cascade
 * over slug-descendants.
 *
 * Throws TreeInternalException when the current slug cannot be
 * resolved (target item missing from overlay or unsorted) — the
 * caller turns this into a "tree changed, please reload" 400.
 *
 * Extracted from TreeMutator::saveImpl's routes-bucket block.
 * Behaviour-identical.
 */
final class RoutesBucketApplier
{
    public function __construct(
        private SlugRenamer $slugRenamer,
        private RoutesUpdater $routes,
        private UnsortedSlugResolver $unsorted,
        private OverlayWriter $overlayWriter,
        private string $treeRoot,
    ) {
    }

    /**
     * @param array<string, mixed> $bucket   routes-bucket from PageMetaFormDecoder
     */
    public function apply(
        string $lang,
        string $section,
        string $indexPath,
        array $bucket,
        TreeMutationLog $log,
    ): RouteResolution {
        // Locate current slug.
        $currentSlug = null;
        if ($section === 'unsorted') {
            $currentSlug = $this->unsorted->resolve($lang, $indexPath);
        } else {
            $overlay = $this->overlayWriter->read($lang);
            if (is_array($overlay[$this->treeRoot][$section] ?? null)) {
                $item = Indexing::locateItem($overlay[$this->treeRoot][$section], $indexPath);
                $currentSlug = is_string($item['slug'] ?? null) ? $item['slug'] : null;
            }
        }
        if ($currentSlug === null) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                'cannot resolve current slug for save',
            );
        }

        $newSlug = isset($bucket['slug']) && is_string($bucket['slug']) ? $bucket['slug'] : $currentSlug;
        $newUrl  = isset($bucket['url'])  && is_string($bucket['url'])  ? $bucket['url']  : null;

        // Slug rename — defer to SlugRenamer. The routes-commit is
        // deferred so a follow-up URL change in the same save batches
        // into one disk write.
        if ($newSlug !== $currentSlug) {
            $this->slugRenamer->rename($lang, $currentSlug, $newSlug, $log);
            $resolvedSlug = $newSlug;
        } else {
            $resolvedSlug = $currentSlug;
        }

        // URL change.
        $urlWasChanged = false;
        if ($newUrl !== null) {
            $routesSnapshotBeforeUrl = $this->routes->readAll();
            $currentUrl = $this->routes->urlForSlug($lang, $resolvedSlug);

            // Smart-default: when a slug rename happens AND the user
            // didn't manually touch the URL field, AND the pre-rename
            // URL's last segment was exactly the old slug, auto-
            // rewrite the URL's last segment to match the new slug.
            // Mirrors create()'s default `composeUrl(parentPrefix,
            // slug)` so a routed page whose URL still reflects its
            // slug stays in sync through a rename. Custom URLs
            // (where the tail differs from the slug) are preserved
            // untouched.
            //
            // Detection: the form pre-fills the URL field with the
            // current value, so when the user only changes the slug
            // the submitted $newUrl equals $currentUrl. If the user
            // explicitly types a URL — even one that happens to
            // coincide with the current — we treat their value as
            // intent and skip the smart-default.
            if ($newSlug !== $currentSlug
                && is_string($currentUrl)
                && $newUrl === $currentUrl
                && UrlDeriver::lastUrlSegment($currentUrl) === $currentSlug) {
                $newUrl = UrlDeriver::replaceLastUrlSegment($currentUrl, $newSlug);
            }

            if ($newUrl !== $currentUrl) {
                $this->routes->changeUrl($lang, $resolvedSlug, $newUrl);
                $log->record(function () use ($routesSnapshotBeforeUrl): void {
                    $this->routes->forceWrite($routesSnapshotBeforeUrl);
                });
                $urlWasChanged = true;
            }
        }

        $this->routes->commit();
        return new RouteResolution($resolvedSlug, $urlWasChanged);
    }
}
