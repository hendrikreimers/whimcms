<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

/**
 * One block-type's full UI schema: human label, description, and
 * an ordered map of fieldName → FieldSchema. Field iteration order
 * drives the on-screen form layout.
 */
final class BlockSchema
{
    /**
     * @param array<string, FieldSchema> $fields
     * @param list<string>               $required  required field names per partial's {@ block @}
     * @param ?FieldSchema               $bodyField  declares that this block accepts a Markdown
     *        body (between the inner `---` separator and the closing `:::`). When non-null the
     *        editor renders an input for it; when null the body is treated as authored-only and
     *        only shown when the loaded block already has body content.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $description,
        public readonly array  $fields,
        public readonly array  $required = [],
        public readonly ?FieldSchema $bodyField = null,
    ) {
    }
}
