<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

use H42\WhimCMS\Content\HrefSanitizer;
use H42\WhimCMS\Content\Identifiers;

/**
 * Decode editor-posted page-meta values into target-bucketed maps
 * suitable for the TreeMutator's save().
 *
 * Input: a flat map of `fieldName => stringValue` matching the field
 * names declared in the active PageType's schema. Output: three
 * buckets keyed by target namespace:
 *
 *   overlay      => map of overlay-item-level keys
 *                   (label, hidden, slug, href, anchor — slug is
 *                   echoed here for reference but actually persists
 *                   via the routes bucket; the writer ignores it.)
 *   routes       => ['slug' => ..., 'url' => ...] (when applicable)
 *   frontmatter  => map of dot-paths
 *                   (layout, meta.title, meta.description, disabled, hidden)
 *
 * Per-field validation happens here (Identifiers patterns, anchor
 * regex, url-path shape, bool coercion). HrefSanitizer is NOT run
 * here — the OverlayWriter's validateItem() is the canonical gate
 * for href values, since the same allowlist is enforced at commit
 * time. Running it twice would only add a second failure mode for
 * the same input.
 *
 * Unknown fields (POSTed but not in the schema) are silently dropped
 * — the schema is the contract, not the request body. This is the
 * conservative direction: a future schema removal does not produce
 * an error in the meantime.
 */
final class PageMetaFormDecoder
{
    /** Byte caps per field flavour. Single-line fields are tight; the
     *  textarea cap matches the public-site AttributeParser ceiling. */
    private const MAX_TEXT_BYTES     = 1024;
    private const MAX_TEXTAREA_BYTES = 4096;

    /**
     * @param list<string> $allowedLayouts  cross-checked at decode for
     *        `layout`-type fields so the editor surfaces a clear
     *        error rather than letting the public-site PageLoader
     *        fall back to the default layout at render time. Empty
     *        list = no cross-check (accept any kebab-case name).
     */
    public function __construct(
        private array $allowedLayouts = [],
    ) {
    }

    /**
     * @param array<string, string|array> $values     posted values keyed by field name
     * @return array{overlay: array<string, mixed>, routes: array<string, string>, frontmatter: array<string, string>}
     */
    public function decode(PageType $type, array $values): array
    {
        $out = [
            'overlay'     => [],
            'routes'      => [],
            'frontmatter' => [],
        ];
        foreach ($type->fields as $fieldName => $field) {
            if (!array_key_exists($fieldName, $values)) {
                continue;
            }
            $raw = $values[$fieldName];
            if (!is_string($raw)) {
                // Drop nested arrays — the page-meta schema is flat.
                continue;
            }
            $decoded = $this->decodeValue($field, $raw);
            $ns  = $field->targetNamespace();
            $key = $field->targetKey();

            if ($ns === 'overlay') {
                $out['overlay'][$key] = $decoded;
            } elseif ($ns === 'routes') {
                if (is_string($decoded)) {
                    $out['routes'][$key] = $decoded;
                }
            } elseif ($ns === 'frontmatter') {
                // Frontmatter values are persisted as strings in the
                // AttributeParser-compatible format. Boolean fields
                // serialise to 'true'/'false'/''.
                if (is_bool($decoded)) {
                    $out['frontmatter'][$key] = $decoded ? 'true' : '';
                } elseif (is_string($decoded)) {
                    $out['frontmatter'][$key] = $decoded;
                }
            }
        }
        return $out;
    }

    private function decodeValue(PageMetaFieldSchema $field, string $raw): mixed
    {
        $raw = trim($raw);
        switch ($field->type) {
            case 'bool':
                return in_array(strtolower($raw), ['true', 'yes', '1', 'on'], true);

            case 'slug':
                if ($raw === '') return '';
                if (!Identifiers::isValidSlug($raw)) {
                    throw new \RuntimeException("Bad slug '{$raw}' (must match " . Identifiers::SLUG_PATTERN . ').');
                }
                return $raw;

            case 'url-path':
                // Match PhpArrayWriter::validateShape's route-segment
                // regex. Empty string is legal (= home page).
                if ($raw === '') return '';
                if (preg_match('#^[a-zA-Z0-9_/-]{1,64}$#', $raw) !== 1) {
                    throw new \RuntimeException("Bad URL path '{$raw}'.");
                }
                if (str_contains($raw, '..')) {
                    throw new \RuntimeException("URL path must not contain '..'.");
                }
                if (str_starts_with($raw, '/') || str_ends_with($raw, '/')) {
                    throw new \RuntimeException("URL path must not start or end with '/'.");
                }
                return $raw;

            case 'anchor':
                if ($raw === '') return '';
                if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $raw) !== 1) {
                    throw new \RuntimeException("Bad anchor '{$raw}'.");
                }
                return $raw;

            case 'layout':
                if ($raw === '') return '';
                if (preg_match('/^[a-z][a-z0-9-]{0,40}$/', $raw) !== 1) {
                    throw new \RuntimeException("Bad layout name '{$raw}'.");
                }
                if ($this->allowedLayouts !== [] && !in_array($raw, $this->allowedLayouts, true)) {
                    throw new \RuntimeException(
                        "Layout '{$raw}' is not in content.allowed_layouts (allowed: "
                        . implode(', ', $this->allowedLayouts) . ').'
                    );
                }
                return $raw;

            case 'select':
                $opts = $field->get('options');
                if (is_array($opts) && !in_array($raw, $opts, true)) {
                    throw new \RuntimeException("Value '{$raw}' is not in the select option list.");
                }
                return $raw;

            case 'textarea':
                if ($raw === '') return '';
                if (preg_match('//u', $raw) !== 1) {
                    throw new \RuntimeException('Value is not valid UTF-8.');
                }
                if (preg_match('/[\x00\r]/', $raw) === 1) {
                    throw new \RuntimeException('Value contains forbidden control bytes (NUL/CR).');
                }
                if (strlen($raw) > self::MAX_TEXTAREA_BYTES) {
                    throw new \RuntimeException('Value exceeds ' . self::MAX_TEXTAREA_BYTES . ' bytes.');
                }
                return $raw;

            case 'link':
                // Defense-in-depth: validate URL allowlist at decode
                // time too, not only at OverlayWriter::validateItem.
                // Path-marker forms (`~/x`, `^/x`) are accepted
                // because I18n::load resolves them at render time —
                // we strip the marker and probe the resolved form.
                if ($raw === '') return '';
                if (preg_match('//u', $raw) !== 1) {
                    throw new \RuntimeException('Value is not valid UTF-8.');
                }
                $probe = (str_starts_with($raw, '~/') || str_starts_with($raw, '^/'))
                    ? substr($raw, 1) : $raw;
                if (HrefSanitizer::check($probe) === null) {
                    throw new \RuntimeException(
                        "URL '{$raw}' is not in the allowlist (https / mailto / tel / /path / #anchor / ~/lang-relative / ^/base-relative)."
                    );
                }
                return $raw;

            case 'text':
            default:
                // Single-line fields: reject any newline / control byte
                // and cap length. Multi-line input on these fields is
                // almost always a paste accident and would either fail
                // a deeper validator or corrupt the .md round-trip
                // (AttributeParser stores values on one line).
                if ($raw === '') return '';
                if (preg_match('//u', $raw) !== 1) {
                    throw new \RuntimeException('Value is not valid UTF-8.');
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
                    throw new \RuntimeException('Single-line fields must not contain newlines or control bytes.');
                }
                if (strlen($raw) > self::MAX_TEXT_BYTES) {
                    throw new \RuntimeException('Value exceeds ' . self::MAX_TEXT_BYTES . ' bytes.');
                }
                return $raw;
        }
    }
}
