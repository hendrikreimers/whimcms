<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * A folder under a language node: one entry from `i18n_overlay.page_tree.sections`
 * plus the synthetic 'unsorted' bucket for pages that don't appear in any
 * configured section.
 *
 * `key` is the literal section name in the overlay (e.g. 'main', 'footer')
 * or the literal string 'unsorted' for the bucket. Mutations key off this.
 */
final class TreeSection
{
    /** @param list<TreeNode> $items */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool   $isUnsorted,
        public readonly array  $items,
    ) {
    }
}
