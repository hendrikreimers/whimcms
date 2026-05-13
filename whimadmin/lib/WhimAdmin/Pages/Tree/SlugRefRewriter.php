<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Rewrite every `slug:` reference in a loaded overlay from one slug
 * value to another, in place. Walks the full overlay tree
 * (root → section → items → children → …) so a renamed slug shows
 * up correctly anywhere it was referenced.
 *
 * Used by `TreeMutator::renameImpl` as the overlay-side counterpart
 * to the routes-side `RoutesUpdater::renameSlug`. Both need to fire
 * inside the same multi-step commit so the new slug is consistent
 * across the .md file, the routes table, and the overlay JSON.
 *
 * Pure structural transformation — no I/O, no validation. The
 * caller (`renameImpl`) has already validated `$newSlug` matches
 * `Identifiers::SLUG_PATTERN`; this class just propagates the
 * value through the tree.
 *
 * Extracted from TreeMutator with no behaviour change — methods
 * are byte-identical (modulo static→static visibility, which was
 * private inside the mutator).
 */
final class SlugRefRewriter
{
    /**
     * Walk the overlay tree (root.section.items.children...) and
     * rewrite every slug-reference from $oldSlug to $newSlug. Mutates
     * the array by reference.
     *
     * @param array<int|string, mixed> &$overlay
     */
    public static function rewriteSlugRefs(array &$overlay, string $oldSlug, string $newSlug): void
    {
        foreach ($overlay as &$topVal) {
            if (!is_array($topVal)) continue;
            foreach ($topVal as &$sectionVal) {
                if (is_array($sectionVal)) {
                    self::rewriteSlugRefsInList($sectionVal, $oldSlug, $newSlug);
                }
            }
            unset($sectionVal);
        }
        unset($topVal);
    }

    /**
     * @param array<int, array<string, mixed>> &$items
     */
    private static function rewriteSlugRefsInList(array &$items, string $oldSlug, string $newSlug): void
    {
        foreach ($items as &$item) {
            if (!is_array($item)) continue;
            if (isset($item['slug']) && $item['slug'] === $oldSlug) {
                $item['slug'] = $newSlug;
            }
            if (isset($item['children']) && is_array($item['children'])) {
                self::rewriteSlugRefsInList($item['children'], $oldSlug, $newSlug);
            }
        }
        unset($item);
    }
}
