<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Pages\RoutesUpdater;

/**
 * URL-segment derivation + cascade for slug-typed page-tree items.
 *
 * Three concerns live here, extracted from TreeMutator without
 * behaviour change:
 *
 *   1. **Pure URL composition** (static): join a parent-segment to a
 *      child's last URL segment (`composeUrl`), pull the last segment
 *      of an arbitrary path (`lastUrlSegment`), rewrite that last
 *      segment (`replaceLastUrlSegment`), kebab-case a label
 *      (`kebabize`).
 *
 *   2. **Parent-segment derivation** (instance, needs routes table):
 *      given a parent item in the overlay, figure out which URL
 *      fragment its slug-children should inherit as a prefix —
 *      `parent.url` for slug parents (via routes lookup), `parent.anchor`
 *      for anchor parents, `kebab(parent.label)` for folder/href.
 *
 *   3. **Cascade** (instance, needs routes + mutation log): re-derive
 *      and apply URLs for every slug descendant under a parent whose
 *      URL-influencing field (label / anchor / url) just changed.
 *      Each child whose new URL differs from current gets
 *      `RoutesUpdater::changeUrl` + a rollback hook on the log.
 *      Collisions on a single child are swallowed so the cascade
 *      doesn't abort.
 *
 * The class takes `RoutesUpdater` and the overlay tree-root key in
 * its constructor. TreeMutator instantiates one (via Kernel-side
 * wiring) and delegates to it from `moveImpl`, `saveImpl`,
 * `retypeImpl`, and `createImpl`'s URL-composition path.
 *
 * Method bodies are byte-identical to the previous TreeMutator
 * private methods — same regex, same error swallowing, same
 * pre-order walk — so the audit's findings on URL handling carry
 * over unchanged.
 */
final class UrlDeriver
{
    public function __construct(
        private RoutesUpdater $routes,
        private string $treeRoot,
    ) {
    }

    // ============================================================
    // Pure URL string operations
    // ============================================================

    /**
     * Compose a URL from an optional parent segment and the slug's
     * last URL segment (or the slug itself if no current URL exists).
     */
    public static function composeUrl(?string $parentSegment, string $tail): string
    {
        $tailLast = self::lastUrlSegment($tail);
        if ($parentSegment === null || $parentSegment === '') {
            return $tailLast;
        }
        return $parentSegment . '/' . $tailLast;
    }

    public static function lastUrlSegment(string $url): string
    {
        if ($url === '') return '';
        $slash = strrpos($url, '/');
        return $slash === false ? $url : substr($url, $slash + 1);
    }

    /**
     * Replace the URL's last path segment with $newTail. Used by the
     * smart-default URL update on slug-rename: an URL whose tail
     * already mirrored the old slug ("demos/imprint" for slug=imprint)
     * gets its tail rewritten ("demos/foo" for slug=foo) so the URL
     * keeps tracking the slug. Multi-segment prefixes are preserved
     * verbatim. For a single-segment URL, the entire URL is replaced.
     */
    public static function replaceLastUrlSegment(string $url, string $newTail): string
    {
        if ($url === '') return $newTail;
        $slash = strrpos($url, '/');
        if ($slash === false) return $newTail;
        return substr($url, 0, $slash) . '/' . $newTail;
    }

    /**
     * Kebab-case a free-form label for use as a URL segment:
     *   lowercase, whitespace → '-', strip anything outside [a-z0-9_-],
     *   collapse repeated '-'.
     *
     * Returns '' when the input has no kebab-safe characters; callers
     * use that as a "skip URL update" signal.
     */
    public static function kebabize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/\s+/', '-', $s) ?? '';
        $s = preg_replace('/[^a-z0-9_-]/', '', $s) ?? '';
        $s = preg_replace('/-+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    // ============================================================
    // Parent-segment derivation (needs routes)
    // ============================================================

    /**
     * Derive the URL-segment of a parent in the overlay tree.
     *
     * Used to keep a moved or freshly-created slug-page's URL aligned
     * with its position in the navigation hierarchy. The mapping is
     * deliberately stable across operation types — move() and
     * create() both call this so the editor sees consistent URLs.
     *
     *   slug parent   → parent.url (full URL, may already be multi-segment)
     *   anchor parent → parent.anchor (already URL-safe per Identifier regex)
     *   folder parent → kebab(parent.label)
     *   href parent   → kebab(parent.label) — href values are external/
     *                   ambiguous; the label is the navigation-meaningful id
     *   root (no parent) → null (caller emits a flat URL)
     *
     * Returns null when the parent index doesn't resolve, when the
     * parent has no derivable segment, or when label-kebabization
     * produces an empty string (label was all-non-ASCII).
     *
     * @param array<int|string, mixed> $overlay
     */
    public function deriveParentSegment(
        array $overlay,
        string $section,
        string $parentIndexPath,
        string $lang,
    ): ?string {
        if ($parentIndexPath === '') return null; // root → no prefix
        $rootKey = $this->treeRoot;
        if (!is_array($overlay[$rootKey][$section] ?? null)) return null;
        try {
            $parent = Indexing::locateItem($overlay[$rootKey][$section], $parentIndexPath);
        } catch (\Throwable) {
            return null;
        }
        if (is_string($parent['slug'] ?? null)) {
            return $this->routes->urlForSlug($lang, $parent['slug']);
        }
        if (is_string($parent['anchor'] ?? null) && $parent['anchor'] !== '') {
            return $parent['anchor'];
        }
        // folder / href / anything else: kebab the label.
        $label = is_string($parent['label'] ?? null) ? $parent['label'] : '';
        $kebab = self::kebabize($label);
        return $kebab === '' ? null : $kebab;
    }

    // ============================================================
    // Cascade (needs routes + mutation log)
    // ============================================================

    /**
     * Re-derive URLs for every slug-typed descendant of $parentIndexPath
     * so they reflect the parent's new segment.
     *
     * Triggered after operations that change a parent's URL segment:
     *   - overlay:label rewrite (folder / href / non-slug parents derive segment from label)
     *   - overlay:anchor rewrite (anchor parents)
     *   - routes:url change on a slug-parent
     *   - move of an item with slug children (children's prefix changes via the moved item's new parent)
     *   - retype that flips a slug-parent to a label-derived segment (or vice-versa)
     *
     * Best-effort: collisions on a single child don't abort the
     * cascade — the failing child keeps its old URL and an audit
     * entry would surface in the calling controller. The cascade
     * doesn't propagate into a child that itself failed (we still
     * recurse into successful ones).
     *
     * Walks the overlay in pre-order so each level's segment is
     * computed against the already-updated state of higher levels.
     *
     * @param array<int|string, mixed> $overlay  (post-mutation in-memory state)
     */
    public function cascade(
        string $lang,
        array $overlay,
        string $section,
        string $parentIndexPath,
        TreeMutationLog $log,
    ): void {
        $rootKey = $this->treeRoot;
        $items = $overlay[$rootKey][$section] ?? null;
        if (!is_array($items)) return;

        $children = [];
        if ($parentIndexPath === '') {
            $children = $items;
        } else {
            try {
                $parent = Indexing::locateItem($items, $parentIndexPath);
            } catch (\Throwable) {
                return;
            }
            $children = is_array($parent['children'] ?? null) ? $parent['children'] : [];
        }

        $i = 0;
        foreach ($children as $child) {
            $childPath = $parentIndexPath === '' ? (string)$i : $parentIndexPath . '/' . $i;
            $i++;
            if (!is_array($child)) continue;

            // Only slug-typed children own a URL we can re-derive.
            $childSlug = is_string($child['slug'] ?? null) ? $child['slug'] : null;
            if ($childSlug !== null) {
                $prefix = $this->deriveParentSegment($overlay, $section, $parentIndexPath, $lang);
                $currentUrl = $this->routes->urlForSlug($lang, $childSlug);
                $newUrl = self::composeUrl($prefix, $currentUrl ?? $childSlug);
                if ($newUrl !== $currentUrl) {
                    $routesSnapshot = $this->routes->readAll();
                    try {
                        $this->routes->changeUrl($lang, $childSlug, $newUrl);
                        $log->record(function () use ($routesSnapshot): void {
                            $this->routes->forceWrite($routesSnapshot);
                        });
                    } catch (\Throwable) {
                        // Collision on this specific child — leave its
                        // URL unchanged, continue the cascade. The
                        // operator can resolve the collision by editing
                        // the URL field of the conflicting page in the
                        // tree editor, or by renaming its slug.
                    }
                }
            }

            // Recurse into grand-children regardless of whether this
            // child was a slug — folders can nest other folders/slugs.
            if (is_array($child['children'] ?? null) && $child['children'] !== []) {
                $this->cascade($lang, $overlay, $section, $childPath, $log);
            }
        }
    }
}
