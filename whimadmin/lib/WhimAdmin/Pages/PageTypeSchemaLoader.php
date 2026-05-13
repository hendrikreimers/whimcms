<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

/**
 * Load the page-type schemas from `whimadmin/config/page-types/*.json`.
 *
 * Parallel structure to Content\BlockSchemaLoader but a simpler trust
 * model: page-type schemas are WhimAdmin-internal (not theme-bound)
 * and the field-type vocabulary is a closed const in PageMetaFieldSchema.
 *
 * Validation chain:
 *   1. Filename basename matches PageType::ID_PATTERN (no traversal).
 *   2. JSON parses, max depth 8.
 *   3. `fields` is an object, each entry has a known `type` and a
 *      structurally valid `target`. Unknown keys at the field-spec
 *      level fail loud (catches typos that would silently lose data).
 *   4. `requiresMd` / `requiresRoute` are derived from the field
 *      targets so they cannot drift from reality.
 *
 * Cached per request — each instance scans the directory once on
 * first `all()` call.
 */
final class PageTypeSchemaLoader
{
    /** Top-level keys allowed in a page-type JSON. */
    private const ALLOWED_TOP_KEYS = ['label', 'description', 'fields'];

    /** Field-spec keys allowed inside `fields.<name>`. */
    private const ALLOWED_FIELD_KEYS = ['type', 'target', 'label', 'required', 'options', 'default'];

    /**
     * Allowed `frontmatter:<key>` targets.
     *
     * MUST stay aligned with the core's PageLoader header allowlist
     * AND the admin-side PageDocument::HEADER_ALLOWED_KEYS /
     * META_ALLOWED_KEYS — a key allowed here but rejected by the
     * .md parser would make saves fail loud at a deeper layer with a
     * less helpful error message. Add a key here only after adding
     * the corresponding entry to:
     *   - lib/WhimCMS/Content/PageLoader.php  (HEADER_ALLOWED_KEYS, META_ALLOWED_KEYS)
     *   - whimadmin/lib/WhimAdmin/Content/PageDocument.php  (same two constants)
     */
    private const ALLOWED_FRONTMATTER_KEYS = [
        'layout',
        'hidden',
        'disabled',
        'meta.title',
        'meta.description',
    ];

    /** Allowed value for `routes:` targets. */
    private const ALLOWED_ROUTES_KEYS = ['url', 'slug'];

    /** Allowed value for `overlay:` targets — fields a tree-item may carry. */
    private const ALLOWED_OVERLAY_KEYS = ['label', 'hidden', 'href', 'anchor', 'slug'];

    /** @var array<string, PageType>|null */
    private ?array $cache = null;

    public function __construct(
        private string $configDir,   // whimadmin/config/page-types
    ) {
    }

    /**
     * @return array<string, PageType>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        $real = realpath($this->configDir);
        if ($real === false) {
            // No directory yet — return empty rather than crash boot.
            // First admin request after deploy will surface the issue
            // as soon as the tree controller actually needs a schema.
            return $this->cache = [];
        }
        foreach ((array)@scandir($real) as $name) {
            if (!is_string($name) || !str_ends_with($name, '.json')) {
                continue;
            }
            $id = substr($name, 0, -5);
            if (preg_match(PageType::ID_PATTERN, $id) !== 1) {
                continue;
            }
            $abs = $real . DIRECTORY_SEPARATOR . $name;
            $resolved = realpath($abs);
            if ($resolved === false || !str_starts_with($resolved, $real . DIRECTORY_SEPARATOR)) {
                continue; // symlink escape
            }
            $out[$id] = $this->loadOne($id, $resolved);
        }
        ksort($out);
        return $this->cache = $out;
    }

    public function get(string $id): ?PageType
    {
        return $this->all()[$id] ?? null;
    }

    private function loadOne(string $id, string $absPath): PageType
    {
        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read page-type schema: {$absPath}");
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Bad page-type JSON for '{$id}': " . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException("Page-type JSON for '{$id}' must be an object.");
        }
        foreach (array_keys($decoded) as $k) {
            if (!in_array($k, self::ALLOWED_TOP_KEYS, true)) {
                throw new \RuntimeException("Page-type '{$id}' has unknown top-level key '{$k}'.");
            }
        }

        $label       = is_string($decoded['label'] ?? null)       ? $decoded['label']       : self::humanise($id);
        $description = is_string($decoded['description'] ?? null) ? $decoded['description'] : '';

        $rawFields = $decoded['fields'] ?? null;
        if (!is_array($rawFields)) {
            throw new \RuntimeException("Page-type '{$id}' is missing required 'fields' object.");
        }

        $fields   = [];
        $required = [];
        $reachableTargets = ['overlay' => false, 'routes' => false, 'frontmatter' => false];

        foreach ($rawFields as $fieldName => $spec) {
            if (!is_string($fieldName) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $fieldName) !== 1) {
                throw new \RuntimeException("Page-type '{$id}' has invalid field name '" . (string)$fieldName . "'.");
            }
            if (!is_array($spec)) {
                throw new \RuntimeException("Page-type '{$id}' field '{$fieldName}' spec must be an object.");
            }
            foreach (array_keys($spec) as $sk) {
                if (!in_array($sk, self::ALLOWED_FIELD_KEYS, true)) {
                    throw new \RuntimeException("Page-type '{$id}' field '{$fieldName}' has unknown key '{$sk}'.");
                }
            }
            $type   = is_string($spec['type']   ?? null) ? $spec['type']   : '';
            $target = is_string($spec['target'] ?? null) ? $spec['target'] : '';
            $label2 = is_string($spec['label']  ?? null) ? $spec['label']  : null;
            $req    = ($spec['required'] ?? false) === true;

            $extra = [];
            if (array_key_exists('options', $spec)) $extra['options'] = $spec['options'];
            if (array_key_exists('default', $spec)) $extra['default'] = $spec['default'];

            $fs = new PageMetaFieldSchema(
                name:     $fieldName,
                type:     $type,
                target:   $target,
                label:    $label2,
                required: $req,
                extra:    $extra,
            );

            // Target-key allowlist per namespace — catches typos at boot.
            $ns  = $fs->targetNamespace();
            $key = $fs->targetKey();
            $allowedForNs = match ($ns) {
                'overlay'     => self::ALLOWED_OVERLAY_KEYS,
                'routes'      => self::ALLOWED_ROUTES_KEYS,
                'frontmatter' => self::ALLOWED_FRONTMATTER_KEYS,
                default       => [],
            };
            if (!in_array($key, $allowedForNs, true)) {
                throw new \RuntimeException(
                    "Page-type '{$id}' field '{$fieldName}' targets '{$target}'; "
                    . "key '{$key}' is not in the {$ns}-target allowlist ("
                    . implode(', ', $allowedForNs) . ')'
                );
            }
            $reachableTargets[$ns] = true;

            $fields[$fieldName] = $fs;
            if ($req) {
                $required[] = $fieldName;
            }
        }

        return new PageType(
            id:             $id,
            label:          $label,
            description:    $description,
            fields:         $fields,
            required:       $required,
            requiresMd:     $reachableTargets['frontmatter'],
            requiresRoute:  $reachableTargets['routes'],
        );
    }

    private static function humanise(string $id): string
    {
        return ucfirst(str_replace('-', ' ', $id));
    }
}
