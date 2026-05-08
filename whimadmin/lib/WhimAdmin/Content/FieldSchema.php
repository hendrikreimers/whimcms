<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

/**
 * One field's UI schema. The `type` drives which view partial renders
 * the input; the rest of the fields hold type-specific config.
 */
final class FieldSchema
{
    public const ALLOWED_TYPES = [
        'text', 'textarea', 'markdown', 'image', 'link', 'bool',
        'number', 'select', 'icon', 'list', 'map',
    ];

    /**
     * @param array<int|string, mixed> $extra type-specific knobs
     *        (options for select, min/max/step for number, of for list,
     *        shape for map, default for any).
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $label = null,
        public readonly array  $extra = [],
    ) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown field type: '{$type}'");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }
}
