<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * One language's slice of the page tree: the language code, the
 * default-lang flag (so the UI can mark it), and the ordered list
 * of sections + the unsorted bucket.
 */
final class LanguageTree
{
    /** @param list<TreeSection> $sections  in i18n_overlay.page_tree.sections order, unsorted appended last */
    public function __construct(
        public readonly string $lang,
        public readonly bool   $isDefault,
        public readonly array  $sections,
    ) {
    }
}
