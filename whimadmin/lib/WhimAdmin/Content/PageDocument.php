<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimCMS\Content\AttributeParser;
use H42\WhimCMS\Content\Identifiers;
use H42\WhimCMS\Content\ParseException;

/**
 * Round-trip-able in-memory model of one content/<lang>/<slug>.md
 * file.
 *
 *   {raw bytes on disk}  ── fromSource() ──▶  PageDocument
 *                                              { header, blocks[] }
 *                              ◀── toSource() ──
 *
 * Two design decisions distinguish this from the core's PageLoader:
 *
 *   1. **Path markers are preserved verbatim.** The core resolves
 *      `~/x` and `^/x` at parse time; whimadmin keeps them raw so
 *      a save reproduces what the author wrote.
 *
 *   2. **Block bodies stay raw Markdown.** The core renders bodies
 *      to safe-subset HTML during page-load; whimadmin keeps them
 *      as authored bytes so the editor can show the source the
 *      operator can edit, and the next page-render goes through
 *      the core's normal pipeline.
 *
 * The parser uses the core's `AttributeParser` (read-only) for the
 * actual key/value parsing, so authoring rules (2-space indent,
 * key shape, max lines, …) are identical between admin and public
 * pipelines. A `.md` that fails to load in the public site fails
 * to load here — a single error model.
 *
 * Validation NOT done here:
 *   - Block-type allowlist (Phase 3 will check against the schema
 *     sidecar + the core's BlockRegistry).
 *   - Layout allowlist (the editor checks this before save).
 *   - Required attribute presence (Phase 3 form-renderer enforces).
 *
 * The structural parse (front-matter shape, block delimiters,
 * attribute syntax, UTF-8 validity) IS enforced — anything that
 * would not round-trip cleanly fails loud at parse time.
 */
final class PageDocument
{
    // Mirrored from the core's PageLoader::HEADER_ALLOWED_KEYS — drift
    // here means the editor can save a .md the public site can't read,
    // or vice versa. Also mirrored at:
    //   whimadmin/lib/WhimAdmin/Pages/PageTypeSchemaLoader.php
    //     → ALLOWED_FRONTMATTER_KEYS
    // — adding a key here without updating that allowlist means a page-
    // type schema with the new frontmatter target would still be
    // rejected at boot.
    //
    // `hidden` controls sitemap inclusion; `disabled` hides the page
    // from the public renderer entirely. Both are scalar booleans in
    // the source, parsed as strings and normalised by the public-side
    // loader.
    private const HEADER_ALLOWED_KEYS = ['layout', 'meta', 'hidden', 'disabled'];
    private const META_ALLOWED_KEYS   = ['title', 'description'];

    /** Accepted string forms for the boolean front-matter flags. */
    private const BOOL_TRUE_FORMS  = ['true', 'yes', '1'];
    private const BOOL_FALSE_FORMS = ['false', 'no', '0', ''];

    /**
     * @param array<string, mixed> $header  Front-matter tree.
     * @param list<Block>          $blocks  Block stream in document order.
     */
    public function __construct(
        public array $header = [],
        public array $blocks = [],
    ) {
    }

    // ============================================================
    // Parsing
    // ============================================================

    /**
     * Parse a Markdown source string into a PageDocument.
     *
     * @throws ParseException on any structural problem.
     */
    public static function fromSource(string $src): self
    {
        $src = self::normaliseLineEndings($src);

        if (preg_match('//u', $src) !== 1) {
            throw new ParseException(
                'Content is not valid UTF-8 — re-save as UTF-8 (without BOM).',
                1
            );
        }
        if (strpos($src, "\0") !== false) {
            throw new ParseException('Content contains a null byte.', 1);
        }

        $lines = explode("\n", $src);
        $n     = count($lines);
        $i     = 0;

        $header = [];
        if ($n > 0 && $lines[0] === '---') {
            $startFm = 1;
            $j = 1;
            while ($j < $n && $lines[$j] !== '---') {
                $j++;
            }
            if ($j >= $n) {
                throw new ParseException('Unclosed front-matter (no closing "---" found).', 1);
            }
            $fmSrc  = implode("\n", array_slice($lines, $startFm, $j - $startFm));
            $header = AttributeParser::parse($fmSrc, $startFm + 1);
            self::validateHeader($header);
            $i = $j + 1;
        }

        $blocks = [];
        while ($i < $n) {
            // Skip blank lines between blocks.
            while ($i < $n && trim($lines[$i]) === '') {
                $i++;
            }
            if ($i >= $n) {
                break;
            }

            $openLine = $i + 1;
            if (preg_match(Identifiers::BLOCK_OPEN_PATTERN, $lines[$i], $m) !== 1) {
                throw new ParseException(
                    'Expected block opener "::: <type>", got: ' . self::quoteForError($lines[$i]),
                    $openLine
                );
            }
            $type = $m[1];
            $i++;

            $attrStart = $i;
            $attrEnd   = null;
            $bodyStart = null;
            $bodyEnd   = null;
            while ($i < $n) {
                $line = $lines[$i];
                if ($line === ':::') {
                    if ($attrEnd === null) {
                        $attrEnd = $i;
                    } else {
                        $bodyEnd = $i;
                    }
                    $i++;
                    break;
                }
                if ($line === '---' && $attrEnd === null) {
                    $attrEnd   = $i;
                    $bodyStart = $i + 1;
                    $i++;
                    continue;
                }
                $i++;
            }
            if ($attrEnd === null) {
                throw new ParseException("Unclosed block opened with '::: {$type}'.", $openLine);
            }
            if ($bodyStart !== null && $bodyEnd === null) {
                throw new ParseException(
                    "Unclosed block opened with '::: {$type}' (body missing close).",
                    $openLine
                );
            }

            $attrSrc = implode("\n", array_slice($lines, $attrStart, $attrEnd - $attrStart));
            $attrs   = AttributeParser::parse($attrSrc, $attrStart + 1);

            $body = null;
            if ($bodyStart !== null && $bodyEnd !== null && $bodyEnd >= $bodyStart) {
                $body = implode("\n", array_slice($lines, $bodyStart, $bodyEnd - $bodyStart));
            }

            $blocks[] = new Block(type: $type, attrs: $attrs, body: $body);
        }

        return new self(header: $header, blocks: $blocks);
    }

    // ============================================================
    // Serialisation
    // ============================================================

    /**
     * Serialise back to a `.md` source string. Round-trip semantic
     * with `fromSource()` — bytes may differ in incidental whitespace
     * (blank-line layout between top-level keys is normalised) but
     * the parsed tree is identical.
     */
    public function toSource(): string
    {
        $out = [];

        if ($this->header !== []) {
            self::validateHeader($this->header);
            $out[] = '---';
            self::serializeAttrs($this->header, 0, $out);
            $out[] = '---';
            $out[] = '';
        }

        $first = true;
        foreach ($this->blocks as $block) {
            if (!$first) {
                $out[] = '';
            }
            $first = false;
            self::serializeBlock($block, $out);
        }

        return implode("\n", $out) . "\n";
    }

    // ============================================================
    // Internals — serialisation
    // ============================================================

    /**
     * Append the lines for one block to $out.
     *
     * @param list<string> &$out
     */
    private static function serializeBlock(Block $block, array &$out): void
    {
        // Match the type DIRECTLY against the type-name regex rather
        // than reconstructing the `::: type` opener and re-matching
        // the full BLOCK_OPEN_PATTERN. Cleaner, and doesn't depend on
        // the opener prefix staying stable.
        if (!Identifiers::isValidBlockType($block->type)) {
            throw new \InvalidArgumentException("Bad block type: '{$block->type}'.");
        }
        $out[] = '::: ' . $block->type;
        self::serializeAttrs($block->attrs, 0, $out);
        if ($block->body !== null) {
            self::validateBody($block->body);
            $out[] = '---';
            // Body is appended verbatim; multi-line bodies emit one
            // line per "\n" segment to keep the surrounding line-by-
            // line emission consistent.
            foreach (explode("\n", $block->body) as $bodyLine) {
                $out[] = $bodyLine;
            }
        }
        $out[] = ':::';
    }

    /**
     * Block bodies are raw Markdown, so LF is permitted (it's how the
     * lines are separated in the source). NUL and a stray bare-`:::`
     * line WOULD break round-trip — both surface a clear error here
     * rather than letting the integrity check in PageRepository::save
     * fail with the cryptic "Content contains a null byte" /
     * "Expected block opener" message from the parser.
     *
     * CR is also rejected: source files are LF-only by convention; a
     * lone CR would be normalised to LF on next read, silently
     * altering the body.
     */
    private static function validateBody(string $body): void
    {
        if (strpbrk($body, "\0\r") !== false) {
            throw new \InvalidArgumentException(
                'Block body contains a forbidden control character (CR/NUL).'
            );
        }
        foreach (explode("\n", $body) as $line) {
            if ($line === ':::') {
                throw new \InvalidArgumentException(
                    'Block body must not contain a line that is exactly ":::" '
                    . '(would close the block prematurely on re-parse). '
                    . 'Indent the line with at least one space if you need to show ":::" verbatim.'
                );
            }
        }
    }

    /**
     * Append the lines for an attribute tree to $out at the given
     * indent. Skips entirely-empty arrays so they round-trip cleanly
     * (an empty list / empty map is not representable in the source
     * format and is equivalent to "key absent").
     *
     * @param array<string, mixed> $attrs
     * @param list<string>         &$out
     */
    private static function serializeAttrs(array $attrs, int $indent, array &$out): void
    {
        $pad = str_repeat(' ', $indent);
        foreach ($attrs as $k => $v) {
            self::validateKey((string)$k);
            if (is_string($v)) {
                if ($v === '') {
                    $out[] = $pad . $k . ':';
                    continue;
                }
                self::validateScalar($v);
                $out[] = $pad . $k . ': ' . $v;
                continue;
            }
            if (is_array($v)) {
                if ($v === []) {
                    continue; // skip empty arrays
                }
                $out[] = $pad . $k . ':';
                if (self::looksLikeList($v)) {
                    self::serializeList($v, $indent + 2, $out);
                } else {
                    self::serializeAttrs($v, $indent + 2, $out);
                }
                continue;
            }
            // Defensive: any other type is a bug in the caller — surface loud.
            throw new \InvalidArgumentException(
                "Cannot serialise attribute '{$k}': unsupported value type " . get_debug_type($v)
            );
        }
    }

    /**
     * @param list<mixed>   $list
     * @param list<string>  &$out
     */
    private static function serializeList(array $list, int $indent, array &$out): void
    {
        // AttributeParser refuses to mix scalars and maps inside one
        // list — reject upfront so the editor gets a clear error
        // instead of the parser's mid-stream "List items must be all
        // scalars or all maps" thrown deep inside the integrity check.
        $itemFormat = null; // 'scalar' | 'map'
        foreach ($list as $item) {
            $thisFormat = is_array($item) ? 'map' : (is_string($item) ? 'scalar' : null);
            if ($thisFormat === null) {
                throw new \InvalidArgumentException(
                    'List item must be string or array, got ' . get_debug_type($item)
                );
            }
            if ($itemFormat !== null && $itemFormat !== $thisFormat) {
                throw new \InvalidArgumentException(
                    'List items must be all scalars or all maps; mixing is not allowed.'
                );
            }
            $itemFormat = $thisFormat;
        }

        $pad = str_repeat(' ', $indent);
        foreach ($list as $item) {
            if (is_string($item)) {
                self::validateScalar($item);
                // `- value` — empty scalar items render as a bare `-`,
                // matching what AttributeParser accepts on parse.
                $out[] = $item === '' ? $pad . '-' : $pad . '- ' . $item;
                continue;
            }
            // is_array($item) — homogeneity loop above guarantees this.
            if ($item === []) {
                throw new \InvalidArgumentException('Empty list items are not allowed.');
            }
            // Map item: "- key: value" on first line, continuation
            // lines at $indent + 2.
            $first = true;
            foreach ($item as $ik => $iv) {
                self::validateKey((string)$ik);
                if (!is_string($iv)) {
                    throw new \InvalidArgumentException(
                        "List-item map values must be strings (key '{$ik}', got "
                        . get_debug_type($iv) . ')'
                    );
                }
                self::validateScalar($iv);
                if ($first) {
                    $line = $iv === ''
                        ? $pad . '- ' . $ik . ':'
                        : $pad . '- ' . $ik . ': ' . $iv;
                    $out[] = $line;
                    $first = false;
                } else {
                    $line = $iv === ''
                        ? str_repeat(' ', $indent + 2) . $ik . ':'
                        : str_repeat(' ', $indent + 2) . $ik . ': ' . $iv;
                    $out[] = $line;
                }
            }
        }
    }

    /**
     * A list (vs nested map) has consecutive integer keys 0..n-1.
     * @param array<int|string, mixed> $a
     */
    private static function looksLikeList(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        $expected = 0;
        foreach (array_keys($a) as $k) {
            if ($k !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private static function validateKey(string $key): void
    {
        // Same shape as AttributeParser — kept in sync deliberately.
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $key) !== 1) {
            throw new \InvalidArgumentException("Invalid attribute key: '{$key}'.");
        }
    }

    /**
     * Reject scalar values that cannot round-trip through the parser.
     *
     * Mirrored from `AttributeParser`'s constraints PLUS one additional
     * rule that is not strictly an attribute-parser limit but a
     * round-trip requirement:
     *
     *   - length ≤ 4096 bytes  (AttributeParser cap)
     *   - no NUL / CR / LF / TAB  (would either break the parser or
     *     silently corrupt the source layout)
     *   - non-empty values must NOT start with a space  (AttributeParser
     *     `ltrim`s the value side, so ` Hello` would re-parse as
     *     `Hello` — a silent drift the editor must not cause)
     *   - non-empty values must NOT consist of spaces only  (would
     *     re-parse as the empty string — covered by the previous rule
     *     since any all-space string starts with a space)
     *
     * Trailing whitespace is preserved by AttributeParser and survives
     * the round-trip cleanly, so we permit it.
     */
    private static function validateScalar(string $v): void
    {
        if (strlen($v) > AttributeParser::MAX_VALUE_LEN) {
            throw new \InvalidArgumentException(
                'Attribute value exceeds ' . AttributeParser::MAX_VALUE_LEN . ' bytes.'
            );
        }
        if (strpbrk($v, "\0\r\n\t") !== false) {
            throw new \InvalidArgumentException(
                'Attribute value contains a forbidden control character (TAB/CR/LF/NUL).'
            );
        }
        if ($v !== '' && $v[0] === ' ') {
            throw new \InvalidArgumentException(
                'Attribute value must not start with whitespace (would not survive a round-trip).'
            );
        }
    }

    // ============================================================
    // Internals — header validation
    // ============================================================

    /**
     * @param array<string, mixed> $header
     */
    private static function validateHeader(array $header): void
    {
        $allowed = array_flip(self::HEADER_ALLOWED_KEYS);
        foreach (array_keys($header) as $k) {
            if (!is_string($k) || !isset($allowed[$k])) {
                throw new ParseException(
                    "Unexpected front-matter key '" . (string)$k . "'.",
                    1
                );
            }
        }
        if (isset($header['layout']) && !is_string($header['layout'])) {
            throw new ParseException("Front-matter 'layout' must be a string.", 1);
        }
        if (isset($header['meta'])) {
            $meta = $header['meta'];
            if (!is_array($meta)) {
                throw new ParseException("Front-matter 'meta' must be a map.", 1);
            }
            $allowedMeta = array_flip(self::META_ALLOWED_KEYS);
            foreach (array_keys($meta) as $k) {
                if (!is_string($k) || !isset($allowedMeta[$k])) {
                    throw new ParseException("Unexpected meta key '" . (string)$k . "'.", 1);
                }
            }
        }
        foreach (['hidden', 'disabled'] as $flag) {
            if (!array_key_exists($flag, $header)) continue;
            $v = $header[$flag];
            if (!is_string($v)) {
                throw new ParseException("Front-matter '{$flag}' must be a string ('true'/'false').", 1);
            }
            $lower = strtolower(trim($v));
            if (!in_array($lower, self::BOOL_TRUE_FORMS, true)
                && !in_array($lower, self::BOOL_FALSE_FORMS, true)) {
                throw new ParseException(
                    "Front-matter '{$flag}' must be one of true/yes/1/false/no/0 (got '{$v}').", 1
                );
            }
        }
    }

    private static function normaliseLineEndings(string $src): string
    {
        $src = preg_replace('/^\xEF\xBB\xBF/', '', $src) ?? $src;
        $src = str_replace("\r\n", "\n", $src);
        return str_replace("\r", "\n", $src);
    }

    private static function quoteForError(string $line): string
    {
        $trimmed = strlen($line) > 80 ? substr($line, 0, 80) : $line;
        return '"' . str_replace(["\0", "\r", "\n", "\t"], ['', '', '\\n', '\\t'], $trimmed) . '"';
    }
}
