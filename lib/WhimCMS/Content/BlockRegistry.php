<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * The set of allowed block types, the partial each one renders with, and
 * the per-type attribute schema. Acts as the only allowlist between
 * authored .md content and the template render path: an unknown block
 * type or a missing/unexpected attribute is a hard error, never a silent
 * skip. This is what makes typos in content files fail loud.
 *
 * Schema today is minimal-but-strict:
 *
 *   - `required` — list of attribute names that must be present.
 *   - `optional` — list of attribute names that may be present.
 *
 * Any attribute supplied in the .md that is not in (required ∪ optional)
 * fails validation. Type-shape checks beyond presence are deliberately
 * not enforced here; block partials are the contract for shape, and the
 * template engine emits empty strings for non-string values via its
 * Sanitizer::stringify(). Adding type-checks here later is straightforward
 * if a template starts depending on stricter shapes.
 *
 * Partial paths are stored as template-engine names (e.g. "partials/blocks/sub-hero")
 * and resolved by the engine's own root-containment logic — no path is
 * taken from .md content into the file system.
 */
final class BlockRegistry
{
    /**
     * @var array<string, array{partial: string, required: list<string>, optional: list<string>}>
     */
    private array $types = [];

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    public function register(string $type, string $partial, array $required = [], array $optional = []): void
    {
        if (!Identifiers::isValidBlockType($type)) {
            throw new \InvalidArgumentException("Invalid block type name: '{$type}'.");
        }
        if (preg_match('#^[A-Za-z0-9/_\-]+$#', $partial) !== 1 || str_contains($partial, '..')) {
            throw new \InvalidArgumentException("Invalid partial path for block '{$type}': '{$partial}'.");
        }
        $this->types[$type] = [
            'partial'  => $partial,
            'required' => array_values($required),
            'optional' => array_values($optional),
        ];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function partialFor(string $type): string
    {
        if (!isset($this->types[$type])) {
            throw new \RuntimeException("No partial registered for block type '{$type}'.");
        }
        return $this->types[$type]['partial'];
    }

    /**
     * Validate the attribute set for a block. Throws on any structural
     * problem; returns silently on success. The line number is forwarded
     * so callers can produce error messages that point at the `:::` opener
     * in the source file.
     *
     * @param array<string, mixed> $attrs
     */
    public function validate(string $type, array $attrs, int $sourceLine): void
    {
        if (!isset($this->types[$type])) {
            throw new ParseException("Unknown block type '{$type}'.", $sourceLine);
        }
        $schema = $this->types[$type];
        $allowed = array_flip(array_merge($schema['required'], $schema['optional']));

        foreach ($schema['required'] as $key) {
            if (!array_key_exists($key, $attrs)) {
                throw new ParseException(
                    "Block '{$type}' is missing required attribute '{$key}'.",
                    $sourceLine
                );
            }
        }
        foreach (array_keys($attrs) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new ParseException(
                    "Block '{$type}' has unexpected attribute '" . (string)$key . "'.",
                    $sourceLine
                );
            }
        }
    }
}
