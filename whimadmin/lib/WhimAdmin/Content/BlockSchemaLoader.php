<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimCMS\Content\Identifiers;
use H42\WhimCMS\Template\Tokenizer;

/**
 * Resolve UI schemas for every block-type the active theme exposes.
 *
 *   1. Discover each block partial's `{@ block @}` annotation via the
 *      core `Tokenizer::scanAnnotations()` (read-only). The annotation
 *      gives us the authoritative `required` + `optional` field name
 *      sets — this is what the public-site BlockRegistry validates
 *      against, so the editor's schema must agree on field NAMES.
 *
 *   2. Look up an optional sidecar JSON at
 *      `whimadmin/config/blocks/<type>.json` for typed field metadata
 *      (text/image/list/map/...). Any field declared in the JSON that
 *      is not in the partial's annotation set is a hard drift error.
 *
 *   3. For every annotation field with no JSON entry, run the
 *      heuristic (name-based) so the editor can render a sensible
 *      input even for custom blocks without a sidecar.
 *
 *   4. Cache resolved schemas per request — boot is per-request, so
 *      this lives only in instance state, not on disk.
 *
 * Field-type allowlist is built from the available view partials in
 * `whimadmin/views/fields/*.html` (excluding `_router.html`). A JSON
 * declaring a type whose partial doesn't exist is a hard error —
 * defence against a sidecar typo silently rendering nothing.
 */
final class BlockSchemaLoader
{
    private const ANNOT_NAME = 'block';

    /** @var array<string, BlockSchema>|null */
    private ?array $cache = null;

    /** @var list<string>|null populated lazily from views/fields/ scan */
    private ?array $allowedTypes = null;

    private Tokenizer $tokenizer;

    public function __construct(
        private string $partialsDir,        // <theme>/templates/partials/blocks
        private string $sidecarDir,         // whimadmin/config/blocks
        private string $fieldsDir,          // whimadmin/views/fields
    ) {
        // Tokenizer is decoupled from directives for scan use; pass a
        // closure that won't be invoked because scanAnnotations() does
        // not enter the directive parser path.
        $this->tokenizer = new Tokenizer(static fn(string $body) =>
            throw new \LogicException('directiveParser unused in scanAnnotations()')
        );
    }

    /**
     * @return array<string, BlockSchema> all known block-type schemas
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $cache = [];
        $partials = $this->discoverPartials();
        foreach ($partials as $type => $absPath) {
            $cache[$type] = $this->resolveSingle($type, $absPath);
        }
        ksort($cache);
        return $this->cache = $cache;
    }

    public function get(string $type): ?BlockSchema
    {
        return $this->all()[$type] ?? null;
    }

    /** @return list<string> allowed field-type names (have a partial) */
    public function allowedFieldTypes(): array
    {
        if ($this->allowedTypes !== null) {
            return $this->allowedTypes;
        }
        $out = [];
        foreach ((array)@scandir($this->fieldsDir) as $name) {
            if (!is_string($name) || !str_ends_with($name, '.html')) {
                continue;
            }
            $base = substr($name, 0, -5);
            if ($base === '_router' || $base === '') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9-]*$/', $base) !== 1) {
                continue;
            }
            $out[] = $base;
        }
        sort($out);
        return $this->allowedTypes = $out;
    }

    /**
     * @return array<string, string> blockType => absolutePath
     */
    private function discoverPartials(): array
    {
        $real = realpath($this->partialsDir);
        if ($real === false) {
            return [];
        }
        $out = [];
        foreach ((array)@scandir($real) as $name) {
            if (!is_string($name) || !str_ends_with($name, '.html')) {
                continue;
            }
            $type = substr($name, 0, -5);
            if (!Identifiers::isValidBlockType($type)) {
                continue;
            }
            $abs = $real . DIRECTORY_SEPARATOR . $name;
            $resolved = realpath($abs);
            if ($resolved === false) {
                continue;
            }
            if (!str_starts_with($resolved, $real . DIRECTORY_SEPARATOR)) {
                continue; // symlink escape
            }
            $out[$type] = $resolved;
        }
        return $out;
    }

    private function resolveSingle(string $type, string $partialPath): BlockSchema
    {
        // 1. Authoritative field set from the partial's annotation.
        $src = @file_get_contents($partialPath);
        if ($src === false) {
            throw new \RuntimeException("Cannot read block partial: {$partialPath}");
        }
        $annot = $this->extractBlockAnnotation($src);
        $required = $this->splitNames($annot['required'] ?? '');
        $optional = $this->splitNames($annot['optional'] ?? '');
        $names    = array_values(array_unique(array_merge($required, $optional)));

        // 2. Sidecar JSON (optional).
        $sidecar = $this->loadSidecar($type);

        // 3. Per-field schema. JSON wins; heuristic fills gaps.
        $allowedTypes = $this->allowedFieldTypes();
        $jsonFields   = is_array($sidecar['fields'] ?? null) ? $sidecar['fields'] : [];

        // Drift check: every JSON field MUST appear in (required ∪ optional).
        $allowed = array_flip($names);
        foreach (array_keys($jsonFields) as $jk) {
            if (!is_string($jk) || !isset($allowed[$jk])) {
                throw new \RuntimeException(
                    "Block '{$type}' sidecar declares field '" . (string)$jk
                    . "' that is not in the partial's {@ block @} annotation."
                );
            }
        }

        $fields = [];
        foreach ($names as $fieldName) {
            $jsonSpec = $jsonFields[$fieldName] ?? null;
            $fields[$fieldName] = $jsonSpec === null
                ? self::heuristic($fieldName)
                : self::fromJson($fieldName, $jsonSpec, $allowedTypes);
        }

        // Optional body-field declaration. JSON shape:
        //   "body": { "type": "markdown", "label": "Code (use ``` fenced blocks)" }
        // When absent, the editor only surfaces the body input for blocks
        // that already have body content on disk (heuristic preservation).
        $bodyField = null;
        $bodySpec  = $sidecar['body'] ?? null;
        if (is_array($bodySpec)) {
            $bodyField = self::fromJson('__body__', $bodySpec, $allowedTypes);
        }

        return new BlockSchema(
            type:        $type,
            label:       is_string($sidecar['label']       ?? null) ? $sidecar['label']       : self::humanise($type),
            description: is_string($sidecar['description'] ?? null) ? $sidecar['description'] : '',
            fields:      $fields,
            required:    $required,
            bodyField:   $bodyField,
        );
    }

    /** @return array<string, string> annotation key/value pairs, flat */
    private function extractBlockAnnotation(string $src): array
    {
        $out = [];
        foreach ($this->tokenizer->scanAnnotations($src) as $annot) {
            if ($annot->name !== self::ANNOT_NAME) {
                continue;
            }
            foreach ($annot->data as $k => $v) {
                if (is_string($v)) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function splitNames(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '' && preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $p) === 1) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function loadSidecar(string $type): ?array
    {
        $path = $this->sidecarDir . DIRECTORY_SEPARATOR . $type . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Bad sidecar JSON for '{$type}': " . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException("Sidecar JSON for '{$type}' must be an object.");
        }
        return $decoded;
    }

    /**
     * @param array<int|string, mixed>|mixed $spec
     * @param list<string> $allowedTypes
     */
    private static function fromJson(string $name, mixed $spec, array $allowedTypes): FieldSchema
    {
        if (!is_array($spec)) {
            throw new \RuntimeException("Field '{$name}' spec must be an object.");
        }
        $type  = is_string($spec['type'] ?? null) ? $spec['type'] : null;
        if ($type === null || !in_array($type, $allowedTypes, true)) {
            throw new \RuntimeException(
                "Field '{$name}' has invalid type '" . (string)$type . "' (allowed: " . implode(', ', $allowedTypes) . ')'
            );
        }
        $label = is_string($spec['label'] ?? null) ? $spec['label'] : null;
        $extra = $spec;
        unset($extra['type'], $extra['label']);
        // Recursive resolution for list.of and map.shape:
        if ($type === 'list' && isset($extra['of']) && is_array($extra['of'])) {
            $extra['of'] = self::fromJson($name . '[]', $extra['of'], $allowedTypes);
        }
        if ($type === 'map' && isset($extra['shape']) && is_array($extra['shape'])) {
            $resolved = [];
            foreach ($extra['shape'] as $k => $v) {
                if (!is_string($k)) continue;
                $resolved[$k] = self::fromJson($name . '.' . $k, $v, $allowedTypes);
            }
            $extra['shape'] = $resolved;
        }
        return new FieldSchema(type: $type, label: $label, extra: $extra);
    }

    /**
     * Name-based heuristic for fields without a sidecar entry. Picks
     * the safest sensible widget for each common pattern in the
     * bundled block library.
     */
    public static function heuristic(string $name): FieldSchema
    {
        if ($name === 'icon') {
            return new FieldSchema('icon');
        }
        if ($name === 'image' || $name === 'bgImage' || $name === 'path'
            || preg_match('/(Image|Img)$/', $name) === 1) {
            return new FieldSchema('image');
        }
        if (preg_match('/Href$/', $name) === 1) {
            return new FieldSchema('link');
        }
        if (preg_match('/Alt$/', $name) === 1) {
            return new FieldSchema('text');
        }
        if ($name === 'body' || $name === 'lede' || $name === 'description') {
            return new FieldSchema('textarea');
        }
        if (preg_match('/^focus[XY]$/', $name) === 1) {
            return new FieldSchema('number', null, ['min' => 0.0, 'max' => 1.0, 'step' => 0.05, 'default' => 0.5]);
        }
        if ($name === 'items') {
            // Heuristic list — caller falls back to scalar list of
            // single-text items; the sidecar should override for
            // map-shaped item lists.
            return new FieldSchema('list', null, [
                'of' => new FieldSchema('text'),
            ]);
        }
        if ($name === 'featured' || $name === 'lightbox' || $name === 'enabled') {
            return new FieldSchema('bool');
        }
        if ($name === 'align') {
            return new FieldSchema('select', null, ['options' => ['start', 'center']]);
        }
        return new FieldSchema('text');
    }

    private static function humanise(string $type): string
    {
        return ucfirst(str_replace('-', ' ', $type));
    }
}
