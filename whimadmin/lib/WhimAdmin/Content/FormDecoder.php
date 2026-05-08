<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimCMS\Content\Identifiers;

/**
 * Turn a POST body into a PageDocument.
 *
 * Expected POST shape (after PHP nested-array decoding):
 *
 *   header[layout]               = 'default'
 *   header[meta][title]          = '...'
 *   header[meta][description]    = '...'
 *   block[<i>][type]             = 'hero'
 *   block[<i>][attr][<key>]      = '...'
 *   block[<i>][attr][items][<j>][<inner>]  for list-of-map fields
 *   block[<i>][body]             = 'markdown body or empty'
 *
 * The decoder uses a per-block BlockSchema to decide list-of-scalar vs
 * list-of-map vs nested map vs scalar. Empty optional fields are
 * pruned so the resulting on-disk source stays minimal.
 */
final class FormDecoder
{
    /**
     * @param array<string, BlockSchema> $schemas blockType => schema
     */
    public function __construct(private array $schemas)
    {
    }

    /**
     * @param array<string, mixed> $post raw $_POST
     */
    public function decode(array $post): PageDocument
    {
        $header = $this->decodeHeader($post['header'] ?? []);
        $blocks = $this->decodeBlocks($post['block']  ?? []);
        return new PageDocument(header: $header, blocks: $blocks);
    }

    // ---------- header ----------

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function decodeHeader(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        $layout = $raw['layout'] ?? '';
        if (is_string($layout) && $layout !== '') {
            $out['layout'] = $layout;
        }
        $meta = $raw['meta'] ?? [];
        if (is_array($meta)) {
            $title = $meta['title'] ?? '';
            $desc  = $meta['description'] ?? '';
            $clean = [];
            if (is_string($title) && $title !== '') $clean['title'] = $title;
            if (is_string($desc)  && $desc  !== '') $clean['description'] = $desc;
            if ($clean !== []) $out['meta'] = $clean;
        }
        return $out;
    }

    // ---------- blocks ----------

    /**
     * @param mixed $raw
     * @return list<Block>
     */
    private function decodeBlocks(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        // Preserve $_POST iteration order — that order matches the
        // browser's submission order which matches DOM order. The
        // drag-and-drop reordering relies on this: the block order on
        // disk after save = the visual order the operator left.
        // (We previously ksort'd by numeric key here, which would
        // override drag results.)
        $out = [];
        foreach ($raw as $k => $blockData) {
            if (!is_array($blockData)) continue;
            if (!is_string($k) && !is_int($k)) continue;
            $type = is_string($blockData['type'] ?? null) ? $blockData['type'] : '';
            if (!Identifiers::isValidBlockType($type)) {
                // Unknown / blank type — skip the block entirely
                // rather than save garbage.
                continue;
            }
            $schema = $this->schemas[$type] ?? null;
            $attrs  = $this->decodeAttrs($blockData['attr'] ?? [], $schema);
            $body   = $this->decodeBody($blockData['body'] ?? null);
            $out[]  = new Block(type: $type, attrs: $attrs, body: $body);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAttrs(mixed $raw, ?BlockSchema $schema): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        if ($schema === null) {
            // Unknown block type — fall back to "everything is a string"
            // and prune empties.
            foreach ($raw as $k => $v) {
                if (!is_string($k)) continue;
                if (is_string($v) && $v !== '') $out[$k] = $v;
            }
            return $out;
        }
        foreach ($schema->fields as $name => $field) {
            $value = $raw[$name] ?? null;
            $decoded = $this->decodeValue($value, $field);
            if ($decoded === null || $decoded === '' || $decoded === [] ) {
                // Skip empties — equivalent to "field not present" for
                // the on-disk source.
                continue;
            }
            $out[$name] = $decoded;
        }
        return $out;
    }

    /**
     * Recursive: returns string | array | null.
     */
    private function decodeValue(mixed $raw, FieldSchema $field): mixed
    {
        return match ($field->type) {
            'text', 'textarea', 'image', 'link', 'icon' => $this->decodeScalar($raw),
            'markdown' => $this->decodeScalarAllowMultiline($raw),
            'bool'     => $this->decodeBool($raw),
            'number'   => $this->decodeNumber($raw),
            'select'   => $this->decodeSelect($raw, $field),
            'list'     => $this->decodeList($raw, $field),
            'map'      => $this->decodeMap($raw, $field),
        };
    }

    private function decodeScalar(mixed $v): string
    {
        if (!is_string($v)) return '';
        // Trim leading whitespace (would not survive AttributeParser
        // ltrim on round-trip) and reject control chars by replacement.
        $clean = ltrim($v, ' ');
        $clean = str_replace(["\0", "\r", "\t"], '', $clean);
        // No newlines in single-line scalar.
        $clean = str_replace("\n", ' ', $clean);
        return $clean;
    }

    private function decodeScalarAllowMultiline(mixed $v): string
    {
        if (!is_string($v)) return '';
        $clean = ltrim($v, ' ');
        $clean = str_replace(["\0", "\r"], '', $clean);
        return $clean;
    }

    private function decodeBool(mixed $v): string
    {
        if ($v === 'true' || $v === '1' || $v === 'on') return 'true';
        return ''; // empty = falsy in templates
    }

    private function decodeNumber(mixed $v): string
    {
        if (!is_string($v) || $v === '') return '';
        // Permit integer or decimal; reject anything else.
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v) !== 1) {
            return '';
        }
        return $v;
    }

    private function decodeSelect(mixed $v, FieldSchema $field): string
    {
        if (!is_string($v)) return '';
        $options = (array)($field->get('options') ?? []);
        return in_array($v, $options, true) ? $v : '';
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $raw, FieldSchema $field): array
    {
        if (!is_array($raw)) return [];
        $of = $field->get('of');
        if (!$of instanceof FieldSchema) return [];
        // Re-index numerically; tolerate gaps from JS remove ops.
        $sorted = [];
        foreach ($raw as $k => $v) {
            // Skip the placeholder key emitted by the list template
            // (`__WIDX__`) — it should never reach the server because
            // JS strips the template before submit, but defence-in-depth.
            if ($k === FormRenderer::LIST_INDEX_PLACEHOLDER) continue;
            if (!is_numeric($k)) continue;
            $sorted[(int)$k] = $v;
        }
        ksort($sorted, SORT_NUMERIC);

        $out = [];
        foreach ($sorted as $itemRaw) {
            $decoded = $this->decodeValue($itemRaw, $of);
            // Skip empty items so saving a list with one cleared row
            // produces a clean shorter list.
            if ($decoded === null || $decoded === '' || $decoded === []) {
                continue;
            }
            $out[] = $decoded;
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function decodeMap(mixed $raw, FieldSchema $field): array
    {
        if (!is_array($raw)) return [];
        $shape = $field->get('shape');
        if (!is_array($shape)) return [];
        $out = [];
        foreach ($shape as $k => $sub) {
            if (!is_string($k) || !$sub instanceof FieldSchema) continue;
            $value = $raw[$k] ?? null;
            $decoded = $this->decodeValue($value, $sub);
            if ($decoded === null || $decoded === '' || $decoded === []) {
                continue;
            }
            // Maps stored on disk are always Map<string, string> — if a
            // sub-field decoded to an array (list/map nested deeper),
            // this would not round-trip. AttributeParser's depth-1 cap
            // means this path is unused in practice; skip defensively.
            if (!is_string($decoded)) continue;
            $out[$k] = $decoded;
        }
        return $out;
    }

    private function decodeBody(mixed $raw): ?string
    {
        if (!is_string($raw)) return null;
        $clean = str_replace(["\0", "\r"], '', $raw);
        return $clean === '' ? null : $clean;
    }
}
