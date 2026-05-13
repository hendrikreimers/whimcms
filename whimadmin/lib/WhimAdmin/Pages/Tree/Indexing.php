<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Pure tree-index manipulation helpers — locate / replace / splice
 * an item inside the overlay-section's nested-array shape, addressed
 * via `indexPath` (slash-separated zero-based indices, e.g. `"3"`,
 * `"3/0"`, `"3/0/1"`).
 *
 * All methods are static and side-effect-free (operating on arrays
 * by value, returning new ones). No filesystem, no routes-table,
 * no logging.
 *
 * Extracted from TreeMutator with no behaviour change — each method
 * body is byte-identical to the previous private static helper.
 * Lives in its own class because:
 *
 *   - The eight methods together are ~270 LOC of pure indexing logic
 *     that obscured the transaction-orchestration code in TreeMutator.
 *   - They are mechanically testable without any I/O dependencies
 *     (if/when a regression suite gets added, this is the easiest
 *     slice to start with).
 *   - The TreeMutator now reads top-to-bottom as one mutation flow
 *     per public method, with `Indexing::X(...)` calls replacing the
 *     previous interleaved `self::X(...)` jumps.
 *
 * `TreeInternalException` is thrown for indexPath mismatches — the
 * caller turns that into a "tree changed, please reload" 400 with
 * a debug-gated detail.
 */
final class Indexing
{
    /**
     * Locate the array node at the given indexPath inside an items
     * list. Returns a copy (PHP-by-value-of-array semantics) — callers
     * that need to mutate must call replaceItem() to commit.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function locateItem(array $items, string $indexPath): array
    {
        if ($indexPath === '') {
            throw new TreeInternalException(
                'Tree address is invalid.',
                'empty indexPath cannot address an item',
            );
        }
        $parts = explode('/', $indexPath);
        $cur = $items;
        $last = count($parts) - 1;
        for ($i = 0; $i <= $last; $i++) {
            if (preg_match('/^\d+$/', $parts[$i]) !== 1) {
                throw new TreeInternalException(
                    'Tree address is invalid.',
                    "bad indexPath segment '{$parts[$i]}'",
                );
            }
            $idx = (int)$parts[$i];
            if (!isset($cur[$idx]) || !is_array($cur[$idx])) {
                throw new TreeInternalException(
                    'The page tree changed since you last viewed it — please reload and retry.',
                    "no item at indexPath '{$indexPath}'",
                );
            }
            if ($i === $last) {
                return $cur[$idx];
            }
            if (!isset($cur[$idx]['children']) || !is_array($cur[$idx]['children'])) {
                throw new TreeInternalException(
                    'The page tree changed since you last viewed it — please reload and retry.',
                    "no children at indexPath segment {$idx}",
                );
            }
            $cur = $cur[$idx]['children'];
        }
        throw new \LogicException('unreachable');
    }

    /**
     * Replace the item at indexPath with $newItem; mutates $items
     * by reference.
     *
     * @param array<int, array<string, mixed>> &$items
     * @param array<string, mixed> $newItem
     */
    public static function replaceItem(array &$items, string $indexPath, array $newItem): void
    {
        $parts = explode('/', $indexPath);
        self::replaceRecurse($items, $parts, 0, $newItem);
    }

    /**
     * @param array<int, array<string, mixed>> &$container
     * @param list<string> $parts
     * @param array<string, mixed> $newItem
     */
    private static function replaceRecurse(array &$container, array $parts, int $depth, array $newItem): void
    {
        if (!isset($parts[$depth])) {
            throw new \LogicException("replaceRecurse: out of parts");
        }
        $idx = (int)$parts[$depth];
        if (!isset($container[$idx]) || !is_array($container[$idx])) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "replaceItem: missing at depth {$depth} (path '" . implode('/', $parts) . "')",
            );
        }
        if ($depth === count($parts) - 1) {
            $container[$idx] = $newItem;
            return;
        }
        if (!isset($container[$idx]['children']) || !is_array($container[$idx]['children'])) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "replaceItem: no children to descend at depth {$depth}",
            );
        }
        self::replaceRecurse($container[$idx]['children'], $parts, $depth + 1, $newItem);
    }

    /**
     * Remove the item at indexPath from $items. Returns
     * `[removedItem, newItems]` — the caller may inspect the removed
     * value and gets a fresh items-array to commit.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{0:array<string, mixed>, 1:array<int, array<string, mixed>>}
     */
    public static function spliceOut(array $items, string $indexPath): array
    {
        $parts = explode('/', $indexPath);
        $removed = null;
        self::spliceOutRecurse($items, $parts, 0, $removed);
        if ($removed === null) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "spliceOut: nothing removed at '{$indexPath}'",
            );
        }
        return [$removed, $items];
    }

    /**
     * @param array<int, array<string, mixed>> &$container
     * @param list<string> $parts
     */
    private static function spliceOutRecurse(array &$container, array $parts, int $depth, ?array &$removed): void
    {
        if (!isset($parts[$depth])) {
            throw new \LogicException("spliceOutRecurse: out of parts");
        }
        $idx = (int)$parts[$depth];
        if (!isset($container[$idx]) || !is_array($container[$idx])) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "spliceOut: missing at depth {$depth} (index {$idx} in path '" . implode('/', $parts) . "')",
            );
        }
        if ($depth === count($parts) - 1) {
            $removed = $container[$idx];
            array_splice($container, $idx, 1);
            return;
        }
        if (!isset($container[$idx]['children']) || !is_array($container[$idx]['children'])) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "spliceOut: no children at depth {$depth}",
            );
        }
        self::spliceOutRecurse($container[$idx]['children'], $parts, $depth + 1, $removed);
    }

    /**
     * Insert $item at $parentIndexPath/$beforeIndex. Returns the new
     * top-level $items array.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $item
     * @return array<int, array<string, mixed>>
     */
    public static function spliceIn(array $items, string $parentIndexPath, int $beforeIndex, array $item): array
    {
        if ($parentIndexPath === '') {
            $beforeIndex = max(0, min(count($items), $beforeIndex));
            array_splice($items, $beforeIndex, 0, [$item]);
            return $items;
        }
        $parts = explode('/', $parentIndexPath);
        self::spliceInRecurse($items, $parts, 0, $beforeIndex, $item);
        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> &$container
     * @param list<string> $parts
     * @param array<string, mixed> $item
     */
    private static function spliceInRecurse(array &$container, array $parts, int $depth, int $beforeIndex, array $item): void
    {
        if (!isset($parts[$depth])) {
            // Reached the parent — splice in.
            $beforeIndex = max(0, min(count($container), $beforeIndex));
            array_splice($container, $beforeIndex, 0, [$item]);
            return;
        }
        $idx = (int)$parts[$depth];
        if (!isset($container[$idx]) || !is_array($container[$idx])) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "spliceIn: missing parent at depth {$depth} (looked for index {$idx} in path '"
                . implode('/', $parts) . "'; container has " . count($container) . ' items)',
            );
        }
        if (!isset($container[$idx]['children']) || !is_array($container[$idx]['children'])) {
            $container[$idx]['children'] = [];
        }
        self::spliceInRecurse($container[$idx]['children'], $parts, $depth + 1, $beforeIndex, $item);
    }

    /**
     * Compute the indexPath of the freshly-inserted item.
     *
     * After spliceIn, the item sits at $beforeIndex within the
     * parent's children-list. parentIndexPath stays unchanged;
     * the resulting indexPath is parent + '/' + beforeIndex (or
     * just beforeIndex when parent is root).
     *
     * @param array<int, array<string, mixed>> $sectionItems
     */
    public static function pathAfterInsert(string $parentIndexPath, int $beforeIndex, array $sectionItems): string
    {
        $idx = (string)$beforeIndex;
        return $parentIndexPath === '' ? $idx : $parentIndexPath . '/' . $idx;
    }

    /**
     * Adjust a sibling-or-descendant target path after spliceOut at
     * `$fromIndexPath` shifted indices in the same parent-array.
     *
     * Concrete case: source=3/3, target=3/3 → after spliceOut at 3/3,
     * demos.children loses index 3. If the target was at 3/3 (or any
     * sibling of source with a higher index than 3), its index has
     * shifted down by one. We rewrite the matching segment of
     * targetPath so spliceIn lands at the intended logical position.
     *
     * Rule: only the segment that lies in the SAME parent-array as
     * the source's leaf is affected. Segments at shallower depths
     * are unaffected (their containers are higher in the tree).
     * Segments at deeper depths are nested inside the target's
     * preceding-segment item, which was NOT removed; their indices
     * are stable.
     */
    public static function adjustPathAfterSplice(string $fromIndexPath, string $targetPath): string
    {
        if ($targetPath === '' || $fromIndexPath === '') return $targetPath;
        $from = explode('/', $fromIndexPath);
        $to   = explode('/', $targetPath);

        // Find the first depth at which the two paths diverge.
        $commonDepth = 0;
        $minLen = min(count($from), count($to));
        while ($commonDepth < $minLen && $from[$commonDepth] === $to[$commonDepth]) {
            $commonDepth++;
        }

        // Only the depth that holds the source's LEAF index needs
        // adjustment, and only if the target also has a segment at
        // that depth (i.e. target is a sibling-or-deeper, not the
        // parent itself).
        $sourceLeafDepth = count($from) - 1;
        if ($commonDepth !== $sourceLeafDepth) return $targetPath;
        if (!isset($to[$sourceLeafDepth])) return $targetPath;

        $fromLeaf = (int)$from[$sourceLeafDepth];
        $toAt     = (int)$to[$sourceLeafDepth];
        if ($toAt > $fromLeaf) {
            $to[$sourceLeafDepth] = (string)($toAt - 1);
        }
        return implode('/', $to);
    }

    public static function parentOfIndexPath(string $indexPath): string
    {
        $slash = strrpos($indexPath, '/');
        return $slash === false ? '' : substr($indexPath, 0, $slash);
    }

    public static function leafOfIndexPath(string $indexPath): int
    {
        $slash = strrpos($indexPath, '/');
        $part  = $slash === false ? $indexPath : substr($indexPath, $slash + 1);
        return (int)$part;
    }
}
