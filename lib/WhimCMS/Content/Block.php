<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Immutable value object: one rendered content block on a page.
 *
 * - `type` is the block-vocabulary identifier (e.g. "sub-hero", "legal-sections")
 *   that maps via the BlockRegistry to a partial under templates/partials/blocks/.
 * - `attrs` is the strictly-validated, schema-checked attribute structure as
 *   parsed from the .md file. All path markers ("~/...", "^/...") have already
 *   been resolved at load time; values are plain strings, ints, or shallow
 *   lists/maps thereof.
 * - `body` is the pre-rendered, sanitized HTML for this block's optional
 *   Markdown body. Empty string when the block has no body. Block partials
 *   that accept a body should emit it via `{!! body !!}` (raw output) — it
 *   has already been through the Markdown renderer's allowlist + the engine
 *   sanitizer, so re-escaping it would break the markup we deliberately allow.
 */
final class Block
{
    /**
     * @param string                $type
     * @param array<string, mixed>  $attrs
     * @param string                $body  Pre-rendered, sanitized HTML.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $attrs,
        public readonly string $body,
    ) {
    }
}
