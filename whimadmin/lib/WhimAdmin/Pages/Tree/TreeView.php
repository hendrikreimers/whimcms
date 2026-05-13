<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Snapshot of the full page tree at a moment in time.
 *
 * `version` is the optimistic-locking handle: a hash over the mtimes of
 * every overlay file + routes.php. A tree mutation in Phase 2 must
 * send this version back; the writer rejects with 409 if the version
 * has moved on (extra-process edit, parallel tab). Visitors of a stale
 * tree see a "reload required" prompt rather than overwriting.
 *
 * `root` is the page-tree root section name from
 * `config/i18n.php → i18n_overlay.page_tree.root` — also emitted so
 * the client knows which overlay key it's editing.
 */
final class TreeView
{
    /** @param list<LanguageTree> $languages */
    public function __construct(
        public readonly string $root,
        public readonly array  $languages,
        public readonly string $version,
    ) {
    }
}
