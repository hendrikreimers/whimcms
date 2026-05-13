<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * One node in the page tree.
 *
 * The shape is union-ish across page types: which fields are populated
 * depends on `type`. Read by the JSON serialiser in PagesTreeController
 * with explicit per-type field whitelisting so a `null` doesn't leak.
 *
 * `indexPath` addresses this node within its section. Format is
 * slash-separated zero-based indices, e.g. "3" = root item 3,
 * "3/0" = first child of root item 3, "3/0/1" = second grandchild,
 * etc. The full mutation address composed by the API is
 * `<lang>:<section>:<indexPath>`.
 *
 * `warnings` surfaces broken / drifting states the aggregator
 * discovered while building the tree (e.g. an overlay slug-ref that
 * has no routes.php entry). Read-only at this stage; Phase 2's editor
 * can offer "fix" actions per warning class.
 */
final class TreeNode
{
    /**
     * @param list<TreeNode> $children
     * @param list<string>   $warnings
     */
    public function __construct(
        public readonly string  $type,         // 'slug' | 'href' | 'anchor' | 'folder'
        public readonly ?string $slug,         // when type=slug; null otherwise
        public readonly ?string $url,          // URL path key from routes.<lang> when type=slug
        public readonly string  $label,        // resolved (overlay-label or fallback)
        public readonly ?string $href,         // when type=href
        public readonly ?string $anchor,       // when type=anchor (without leading #)
        public readonly bool    $hidden,       // overlay hidden flag
        public readonly bool    $disabled,     // .md front-matter disabled flag (slug only)
        public readonly bool    $hasMd,        // .md file exists on disk (slug only)
        public readonly array   $children,
        public readonly string  $indexPath,    // e.g. "3" or "3/0"
        public readonly array   $warnings,
    ) {
    }
}
