<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

/**
 * One page-meta field's UI schema.
 *
 * Parallel to Content\FieldSchema but with a strictly different type
 * vocabulary: page-meta fields persist to different targets (overlay
 * JSON, .md front-matter, routes.php) and need shapes that wouldn't
 * make sense for a content block attribute — slug, url-path, layout,
 * anchor. Keeping the two schemas separate prevents drift between the
 * content-editing pipeline and the page-management pipeline.
 *
 * Allowed field types in this schema are a fixed const — unlike
 * Content\BlockSchemaLoader, which cross-checks against views/fields/
 * because content blocks can reference any field-renderer the theme
 * configures. Page-meta fields are WhimAdmin-internal and the set is
 * closed.
 *
 * The `target` field expresses where this field persists:
 *
 *   overlay:<key>             — top-level key in the per-language
 *                               _i18n_overlay.<lang>.json item object
 *                               (e.g. overlay:label, overlay:hidden,
 *                               overlay:href, overlay:anchor).
 *   routes:url                — URL path key in routes.<lang>.
 *   routes:slug               — slug-value in routes.<lang>; also the
 *                               filename stem of content/<lang>/<slug>.md.
 *   frontmatter:<dot.path>    — dot-path into the .md front-matter
 *                               (e.g. frontmatter:layout,
 *                               frontmatter:meta.title,
 *                               frontmatter:disabled).
 *
 * The target is validated structurally here; per-target semantic
 * checks (e.g. "url must be a valid URL path") live in Phase 2's
 * decoder + writer pipeline.
 */
final class PageMetaFieldSchema
{
    public const ALLOWED_TYPES = [
        'text', 'textarea', 'bool', 'select', 'link',
        'slug', 'url-path', 'anchor', 'layout',
    ];

    public const TARGET_NAMESPACES = ['overlay', 'routes', 'frontmatter'];

    public const TARGET_PATTERN = '/^(overlay|routes|frontmatter):[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/';

    /**
     * @param array<int|string, mixed> $extra type-specific knobs
     *        (options for select, default for any).
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $type,
        public readonly string  $target,
        public readonly ?string $label = null,
        public readonly bool    $required = false,
        public readonly array   $extra = [],
    ) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown page-meta field type '{$type}' (allowed: " . implode(', ', self::ALLOWED_TYPES) . ')'
            );
        }
        if (preg_match(self::TARGET_PATTERN, $target) !== 1) {
            throw new \InvalidArgumentException(
                "Bad page-meta field target '{$target}' (expected '<namespace>:<key>' where namespace is one of "
                . implode(', ', self::TARGET_NAMESPACES) . ')'
            );
        }
    }

    /** 'overlay', 'routes', 'frontmatter' */
    public function targetNamespace(): string
    {
        $colon = strpos($this->target, ':');
        return $colon === false ? '' : substr($this->target, 0, $colon);
    }

    /** Path inside the target namespace (e.g. 'meta.title' for frontmatter:meta.title) */
    public function targetKey(): string
    {
        $colon = strpos($this->target, ':');
        return $colon === false ? '' : substr($this->target, $colon + 1);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }
}
