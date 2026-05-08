<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

/**
 * Mutable, in-memory representation of one content block.
 *
 * The whimadmin editor mutates these objects (rename type, edit
 * attrs, swap body, reorder in the parent PageDocument). The
 * `attrs` shape mirrors what `H42\WhimCMS\Content\AttributeParser`
 * returns:
 *
 *   - scalar attribute      → string
 *   - nested map (depth 1)  → array<string, string>
 *   - list of scalars       → list<string>
 *   - list of maps          → list<array<string, string>>
 *
 * Path-marker resolution (`~/x`, `^/x`) is intentionally NOT applied
 * here — the editor needs round-trip preservation of the raw values
 * an author wrote. The core's `H42\WhimCMS\Content\PageLoader`
 * resolves markers at runtime when rendering for the public site;
 * whimadmin does not, so saving a block reproduces the same source
 * text the operator typed.
 *
 * `body` holds the verbatim raw Markdown body (everything between
 * the optional `---` separator and the closing `:::`). It is `null`
 * when the block carries no body. The body is NEVER pre-rendered to
 * HTML on the admin side — that's the public-render path's concern.
 */
final class Block
{
    /**
     * @param array<string, mixed> $attrs Raw attribute tree per
     *        AttributeParser semantics (strings, lists, maps).
     */
    public function __construct(
        public string $type,
        public array $attrs = [],
        public ?string $body = null,
    ) {
    }

    /**
     * Deep clone — used by the cut/paste flow so a clipboard'd block
     * can be inserted multiple times without aliasing the source's
     * attribute arrays.
     *
     * Depth-capped at 8 (well above the AttributeParser-permitted
     * structural depth of 2) so a malformed in-memory tree from an
     * external caller cannot trigger unbounded recursion here.
     */
    public function cloneDeep(): self
    {
        return new self(
            type:  $this->type,
            attrs: self::deepCopyArray($this->attrs, 0),
            body:  $this->body,
        );
    }

    private const MAX_CLONE_DEPTH = 8;

    /**
     * @param array<int|string, mixed> $arr
     * @return array<int|string, mixed>
     */
    private static function deepCopyArray(array $arr, int $depth): array
    {
        if ($depth > self::MAX_CLONE_DEPTH) {
            throw new \RuntimeException('Block::cloneDeep exceeded depth cap.');
        }
        $out = [];
        foreach ($arr as $k => $v) {
            $out[$k] = is_array($v) ? self::deepCopyArray($v, $depth + 1) : $v;
        }
        return $out;
    }
}
