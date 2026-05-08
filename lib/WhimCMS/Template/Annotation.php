<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

use H42\WhimCMS\Content\AttributeParser;
use H42\WhimCMS\Content\ParseException;

/**
 * Compile-time annotation extracted from a `{@ name … @}` block.
 *
 * Annotations are declarative metadata for directives — block schemas,
 * cache hints, layout markers, anything a directive wants to attach to
 * a template at compile time without affecting its rendered output. They
 * never produce an output token: the Tokenizer extracts them in a separate
 * pass and they live only in the Engine's annotation registry.
 *
 * Body shape (between `{@` and `@}`):
 *
 *   {@ block
 *     required: title
 *     optional: id eyebrow lede
 *   @}
 *
 *   - First non-blank line: the annotation name (`block`). Validated
 *     against [a-z][a-z0-9-]*. The Engine uses this to dispatch to the
 *     right AnnotationConsumer.
 *   - Remaining lines: a key/value map in the same syntax as content
 *     attribute blocks — parsed via AttributeParser. Indented two spaces
 *     for visual alignment with the `{@`/`@}` markers; the parser strips
 *     the indent before parsing.
 *
 * Why reuse AttributeParser instead of a custom mini-parser:
 *   - Authors already know the syntax from `.md` content files.
 *   - The strict ruleset (no quoting, no flow style, no anchors, …) is
 *     already audited.
 *   - Failure modes are familiar: line-accurate ParseException with a
 *     single source-line offset.
 */
final class Annotation
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';

    /**
     * @param array<string, string> $data
     */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly int $sourceLine,
    ) {
    }

    /**
     * Parse the body of one `{@ … @}` block. The body is the raw text
     * between the markers (the markers themselves already stripped).
     *
     * `$bodyStartLine` is the line number of the first body line in the
     * source file, so error messages from AttributeParser point at the
     * right place.
     *
     * @throws ParseException on any structural problem in the body
     * @throws \RuntimeException on a malformed annotation name
     */
    public static function parse(string $body, int $bodyStartLine): self
    {
        // Drop fully-blank leading lines so the name-line search is
        // robust to whitespace right after the opening `{@`.
        $lines = explode("\n", $body);
        $skip = 0;
        while ($skip < count($lines) && trim($lines[$skip]) === '') {
            $skip++;
        }
        if ($skip === count($lines)) {
            throw new \RuntimeException('Empty annotation body.');
        }
        $nameLineIdx = $skip;
        $name = trim($lines[$nameLineIdx]);
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \RuntimeException("Invalid annotation name: '{$name}'.");
        }

        // Remaining lines hold the key/value body. They're conventionally
        // indented two spaces for visual alignment with `{@` and `@}`;
        // strip exactly that indent so AttributeParser sees top-level
        // keys at column 0. Lines with less indent (or none) pass
        // through unchanged and will fail AttributeParser's column-0
        // check loudly — exactly the right behaviour for a typo.
        $bodyLines = array_slice($lines, $nameLineIdx + 1);
        $dedented  = [];
        foreach ($bodyLines as $line) {
            $dedented[] = str_starts_with($line, '  ') ? substr($line, 2) : $line;
        }
        $attrSrc = implode("\n", $dedented);
        // Trim trailing blank lines — common right before `@}` and would
        // otherwise show up as empty top-level entries to AttributeParser.
        $attrSrc = rtrim($attrSrc, "\n");

        $data = $attrSrc === ''
            ? []
            : AttributeParser::parse($attrSrc, $bodyStartLine + $nameLineIdx + 1);

        // AttributeParser may produce nested maps/lists for some inputs;
        // annotations are intentionally flat (string → string) so a
        // directive consuming them never has to defend against nested
        // shapes. Reject anything else here, with the source line of the
        // offending key.
        $flat = [];
        foreach ($data as $k => $v) {
            if (!is_string($v)) {
                throw new \RuntimeException("Annotation '{$name}' field '{$k}' must be a flat string value.");
            }
            $flat[$k] = $v;
        }

        return new self($name, $flat, $bodyStartLine);
    }
}
