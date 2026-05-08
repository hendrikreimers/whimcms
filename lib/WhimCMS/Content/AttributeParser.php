<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Strict mini-key-value parser for page front-matter and block attributes.
 *
 * Grammar — every form deliberately small to keep the parser auditable:
 *
 *   key: value              # scalar (string)
 *
 *   key:                    # nested map (depth = 1 only, no further)
 *     innerKey: value
 *     innerKey: value
 *
 *   key:                    # list of scalars
 *     - value
 *     - value
 *
 *   key:                    # list of maps (depth = 1 inside each item)
 *     - mapKey: value
 *       mapKey: value
 *     - mapKey: value
 *       mapKey: value
 *
 * Hard rules — every deviation throws ParseException with the source line:
 *
 *   - Keys: ^[a-zA-Z][a-zA-Z0-9_]{0,63}$ — no dots, no dashes, no quoting.
 *   - Indentation: TWO SPACES per level. Tabs are forbidden anywhere.
 *   - Values: the trimmed remainder of the line. No quote stripping, no
 *     escape sequences. A leading `~` or `^` on a value is preserved
 *     verbatim — path-marker resolution happens later, in PageLoader.
 *   - Maximum total lines: 500. Maximum value length: 4096 bytes.
 *     Maximum key length: 64 chars.
 *   - Blank lines allowed between top-level keys only.
 *   - Comments are not supported. Lines that are not blank and do not match
 *     one of the recognised forms are errors.
 *   - Duplicate keys at the same level are errors.
 *
 * What this parser intentionally does NOT do — and won't be extended to do
 * without a security review:
 *   - YAML anchors / refs / `<<`
 *   - Multi-document streams
 *   - Flow style ([a, b], {k: v})
 *   - Quoting / escape sequences
 *   - Type coercion (booleans, ints, floats — everything is a string)
 *   - Heredoc-style multi-line scalars
 *   - Comments
 *   - Tabs as indentation
 *
 * Each of the above is either a known footgun (anchors, multi-doc) or
 * complexity that does not pay for itself in this project. Fewer features
 * = fewer paths an audit has to cover.
 */
final class AttributeParser
{
    public const MAX_LINES     = 500;
    public const MAX_KEY_LEN   = 64;
    public const MAX_VALUE_LEN = 4096;

    private const KEY_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/';

    /**
     * Parse a key/value source slice into a nested array.
     *
     * @param string $src           The attribute source — one line per row,
     *                              separated by "\n". Trailing newline ok.
     * @param int    $sourceLineOffset  Line number in the original file at
     *                              which this slice begins, used to make
     *                              error messages line-accurate.
     *
     * @return array<string, mixed>
     *
     * @throws ParseException
     */
    public static function parse(string $src, int $sourceLineOffset = 1): array
    {
        $lines = $src === '' ? [] : explode("\n", rtrim($src, "\n"));
        if (count($lines) > self::MAX_LINES) {
            throw new ParseException(
                'Attribute block exceeds the maximum of ' . self::MAX_LINES . ' lines.',
                $sourceLineOffset
            );
        }
        // Tabs anywhere = hard reject. Keeps indentation deterministic.
        foreach ($lines as $idx => $line) {
            if (strpos($line, "\t") !== false) {
                throw new ParseException(
                    'Tabs are not allowed in attributes; use 2-space indentation.',
                    $sourceLineOffset + $idx
                );
            }
        }

        $i = 0;
        $n = count($lines);
        $out = [];

        while ($i < $n) {
            $rawLine = $lines[$i];
            $absLine = $sourceLineOffset + $i;

            if (trim($rawLine) === '') {
                $i++;
                continue;
            }

            $indent = self::leadingSpaces($rawLine);
            if ($indent !== 0) {
                throw new ParseException(
                    'Top-level key expected at column 0 (got ' . $indent . ' spaces).',
                    $absLine
                );
            }

            [$key, $valueOnSameLine] = self::splitKeyValue($rawLine, $absLine);
            if (array_key_exists($key, $out)) {
                throw new ParseException("Duplicate top-level key '{$key}'.", $absLine);
            }

            if ($valueOnSameLine !== null) {
                $out[$key] = self::validatedScalar($valueOnSameLine, $absLine);
                $i++;
                continue;
            }

            // No same-line value → either nested map or list, depending on
            // what the next non-blank line looks like.
            $nextIdx = self::peekNextNonBlank($lines, $i + 1);
            if ($nextIdx === null) {
                // `key:` with no body — empty value. Treat as empty string.
                $out[$key] = '';
                $i++;
                continue;
            }
            $nextLine = $lines[$nextIdx];
            $nextIndent = self::leadingSpaces($nextLine);

            if ($nextIndent === 0) {
                // No nesting — empty scalar.
                $out[$key] = '';
                $i++;
                continue;
            }
            if ($nextIndent !== 2) {
                throw new ParseException(
                    'Nested entries under "' . $key . '" must be indented exactly 2 spaces (got ' . $nextIndent . ').',
                    $sourceLineOffset + $nextIdx
                );
            }

            $trimmed = ltrim($nextLine, ' ');
            if (str_starts_with($trimmed, '- ') || $trimmed === '-') {
                [$value, $consumed] = self::parseList($lines, $nextIdx, $sourceLineOffset);
                $out[$key] = $value;
                $i = $consumed;
            } else {
                [$value, $consumed] = self::parseMap($lines, $nextIdx, 2, $sourceLineOffset);
                $out[$key] = $value;
                $i = $consumed;
            }
        }

        return $out;
    }

    /**
     * Parse a flat map indented at the given column. Stops on the first
     * line that is blank-and-followed-by-an-out-dent, OR the first line
     * with smaller indentation than expected.
     *
     * @param list<string> $lines
     * @return array{0: array<string, string>, 1: int}
     */
    private static function parseMap(array $lines, int $i, int $expectedIndent, int $sourceLineOffset): array
    {
        $n = count($lines);
        $map = [];
        while ($i < $n) {
            $line = $lines[$i];
            $absLine = $sourceLineOffset + $i;
            if (trim($line) === '') {
                // Look ahead — if the next non-blank line is at the same
                // indent or deeper, we continue this map; otherwise the
                // map ends here.
                $nextIdx = self::peekNextNonBlank($lines, $i + 1);
                if ($nextIdx === null) {
                    $i++;
                    break;
                }
                if (self::leadingSpaces($lines[$nextIdx]) < $expectedIndent) {
                    break;
                }
                $i++;
                continue;
            }

            $indent = self::leadingSpaces($line);
            if ($indent < $expectedIndent) {
                break;
            }
            if ($indent !== $expectedIndent) {
                throw new ParseException(
                    'Inner-map entry must be indented ' . $expectedIndent . ' spaces (got ' . $indent . ').',
                    $absLine
                );
            }

            $body = substr($line, $expectedIndent);
            [$key, $value] = self::splitKeyValue($body, $absLine);
            if (array_key_exists($key, $map)) {
                throw new ParseException("Duplicate map key '{$key}'.", $absLine);
            }
            // A bare `key:` (no value, no nested content) is treated as
            // an empty string — same semantics as at the top level. Authors
            // legitimately use this for "image: " etc. when a JSON-source
            // had `"image": ""`. Further nesting is still forbidden because
            // the next line would either be at the same indent (handled by
            // this loop) or at deeper indent (which we explicitly reject).
            $map[$key] = $value === null ? '' : self::validatedScalar($value, $absLine);
            $i++;
        }
        return [$map, $i];
    }

    /**
     * Parse a list of items indented at column 2. Each item is either a
     * scalar (`- value`) or a flat map (`- key: value` then continuation
     * lines at column 4 with `key: value`). Items cannot mix scalar/map.
     *
     * @param list<string> $lines
     * @return array{0: list<mixed>, 1: int}
     */
    private static function parseList(array $lines, int $i, int $sourceLineOffset): array
    {
        $n = count($lines);
        $list = [];
        $itemFormat = null; // 'scalar' | 'map'

        while ($i < $n) {
            $line = $lines[$i];
            $absLine = $sourceLineOffset + $i;

            if (trim($line) === '') {
                $nextIdx = self::peekNextNonBlank($lines, $i + 1);
                if ($nextIdx === null) {
                    $i++;
                    break;
                }
                if (self::leadingSpaces($lines[$nextIdx]) < 2) {
                    break;
                }
                $i++;
                continue;
            }

            $indent = self::leadingSpaces($line);
            if ($indent < 2) {
                break;
            }
            if ($indent !== 2) {
                throw new ParseException(
                    'List item must be indented 2 spaces (got ' . $indent . ').',
                    $absLine
                );
            }
            $body = substr($line, 2);

            if (!str_starts_with($body, '- ') && $body !== '-') {
                throw new ParseException(
                    'Expected list item starting with "- " at column 2.',
                    $absLine
                );
            }

            $itemBody = $body === '-' ? '' : substr($body, 2);
            $i++;

            // Disambiguate scalar items from map items by inspecting the
            // would-be key. A natural-language scalar like "Anyone with a
            // specific goal: a fight" contains `: ` but the part before it
            // is not a valid key (KEY_PATTERN rejects spaces). Only when
            // the prefix matches KEY_PATTERN and is followed by `:` or
            // `: …` do we commit to map-item parsing.
            if ($itemBody === '' || !self::looksLikeMapEntry($itemBody)) {
                // Scalar item: `- value` with no key:value pair. Continuation
                // lines at column 4 are not allowed for scalar items; if any
                // appear, the next iteration's column-2 expectation will
                // raise a clear "must be indented 2 spaces" error.
                if ($itemFormat === 'map') {
                    throw new ParseException(
                        'List items must be all scalars or all maps; mixing is not allowed.',
                        $absLine
                    );
                }
                $itemFormat = 'scalar';
                $list[] = self::validatedScalar($itemBody, $absLine);
                continue;
            }

            // Map item: `- key: value` on the same line, optional continuation.
            [$key, $value] = self::splitKeyValue($itemBody, $absLine);
            if ($value === null) {
                throw new ParseException(
                    "Map-item key '{$key}' must have a same-line value.",
                    $absLine
                );
            }
            $entry = [$key => self::validatedScalar($value, $absLine)];

            $nextIdx = self::peekNextNonBlank($lines, $i);
            if ($nextIdx !== null && self::leadingSpaces($lines[$nextIdx]) === 4) {
                [$rest, $consumed] = self::parseMap($lines, $i, 4, $sourceLineOffset);
                foreach ($rest as $rk => $rv) {
                    if (array_key_exists($rk, $entry)) {
                        throw new ParseException(
                            "Duplicate map key '{$rk}' in list item.",
                            $sourceLineOffset + $consumed
                        );
                    }
                    $entry[$rk] = $rv;
                }
                $i = $consumed;
            }

            if ($itemFormat === 'scalar') {
                throw new ParseException(
                    'List items must be all scalars or all maps; mixing is not allowed.',
                    $absLine
                );
            }
            $itemFormat = 'map';
            $list[] = $entry;
        }

        return [$list, $i];
    }

    /**
     * Split "key: value" into [key, value]. Returns [key, null] when the
     * line is exactly "key:" with no value. Validates the key shape.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitKeyValue(string $body, int $absLine): array
    {
        $colonPos = strpos($body, ':');
        if ($colonPos === false) {
            throw new ParseException("Expected 'key: value' (no ':' found).", $absLine);
        }
        $key = substr($body, 0, $colonPos);
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ParseException("Invalid key '{$key}' (expected ^[a-zA-Z][a-zA-Z0-9_]{0,63}$).", $absLine);
        }
        $rest = substr($body, $colonPos + 1);
        if ($rest === '') {
            return [$key, null];
        }
        if ($rest[0] !== ' ') {
            throw new ParseException("Expected one space after ':' in key '{$key}'.", $absLine);
        }
        $value = ltrim(substr($rest, 1), ' ');
        if ($value === '') {
            return [$key, null];
        }
        return [$key, $value];
    }

    /**
     * Does this string look like a `key: value` map entry — i.e. is the
     * part before the first colon a syntactically valid key per
     * KEY_PATTERN, and is the colon followed by either end-of-string or
     * a space? If yes, dispatch to map-item parsing; otherwise treat the
     * whole string as a scalar item. This is what lets natural-language
     * list items containing `: ` (e.g. "Anyone with a specific goal: a
     * fight") parse as scalars instead of failing on the apparent key.
     */
    private static function looksLikeMapEntry(string $body): bool
    {
        $colonPos = strpos($body, ':');
        if ($colonPos === false) {
            return false;
        }
        $key = substr($body, 0, $colonPos);
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return false;
        }
        $after = $colonPos + 1;
        return $after >= strlen($body) || $body[$after] === ' ';
    }

    private static function validatedScalar(string $value, int $absLine): string
    {
        if (strlen($value) > self::MAX_VALUE_LEN) {
            throw new ParseException(
                'Value exceeds maximum length of ' . self::MAX_VALUE_LEN . ' bytes.',
                $absLine
            );
        }
        if (strpbrk($value, "\0\r") !== false) {
            throw new ParseException('Value contains forbidden control character.', $absLine);
        }
        return $value;
    }

    private static function leadingSpaces(string $line): int
    {
        $i = 0;
        $n = strlen($line);
        while ($i < $n && $line[$i] === ' ') {
            $i++;
        }
        return $i;
    }

    /**
     * @param list<string> $lines
     */
    private static function peekNextNonBlank(array $lines, int $from): ?int
    {
        $n = count($lines);
        for ($i = $from; $i < $n; $i++) {
            if (trim($lines[$i]) !== '') {
                return $i;
            }
        }
        return null;
    }
}
