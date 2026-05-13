<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

/**
 * One page-type's editor schema.
 *
 * A page-type is a way the editor pegs a navigation entry: a routed
 * page with its own URL and .md content (`slug`), an external link
 * (`href`), an in-page anchor (`anchor`), or a pure grouping container
 * with children but no own URL (`folder`).
 *
 * Each type ships a JSON spec under `whimadmin/config/page-types/<id>.json`
 * that lists the fields the editor shows on the right pane. Field
 * iteration order drives form layout.
 *
 * `requiresMd` / `requiresRoute` are derived from the field set —
 * the type-switcher in Phase 2 uses them to plan side-effects when
 * the editor flips a page between types (create/delete .md, add/remove
 * routes.php entry).
 */
final class PageType
{
    /** Allowed page-type identifiers. Filename = id. */
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{0,32}$/';

    /**
     * @param array<string, PageMetaFieldSchema> $fields  fieldName => schema, declaration order preserved
     * @param list<string>                       $required  field names flagged as required
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly array  $fields,
        public readonly array  $required,
        public readonly bool   $requiresMd,
        public readonly bool   $requiresRoute,
    ) {
    }

    /** True if any field of this type persists to the given namespace. */
    public function usesTargetNamespace(string $namespace): bool
    {
        foreach ($this->fields as $f) {
            if ($f->targetNamespace() === $namespace) {
                return true;
            }
        }
        return false;
    }
}
