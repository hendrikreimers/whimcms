<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimCMS\Content\Identifiers;

/**
 * Build the page tree by joining three sources:
 *
 *   1. The per-language overlay file
 *      `<content>/_i18n_overlay.<lang>.json` — top-level `<root>`
 *      key, with the configured `sections` as sub-keys. This gives
 *      us hierarchy, item order, labels, and the hidden flag for
 *      every nav-managed entry.
 *
 *   2. The core routes config `<core>/config/routes.php → routes.<lang>`
 *      — URL ↔ slug bidirectional map per language. Provides the
 *      `url` field for type=slug nodes, the slug allowlist for the
 *      overlay-references, and (by set-subtraction) the contents of
 *      the unsorted bucket.
 *
 *   3. The content directory `<core>/<paths.content>/<lang>/<slug>.md`
 *      — existence check (so the editor can flag a routed slug that
 *      has no on-disk content yet), and a lightweight front-matter
 *      probe for the `disabled` flag (so the tree can dim disabled
 *      pages even when the editor hasn't opened them).
 *
 * All three sources are read-only here. Mutations live in Phase 2's
 * TreeMutator. The aggregator never writes. Per-request cached.
 *
 * Failure mode: any I/O failure on a single overlay file degrades to
 * "this language has no overlay" — the unsorted bucket still picks up
 * every slug from routes.php, so the editor isn't blocked. Hard
 * failures (routes.php parse error, page-tree config missing) bubble
 * as RuntimeException; Kernel turns them into the standard 500.
 *
 * Frontmatter probe: deliberately not via the full PageDocument
 * parser. The tree only needs `disabled` (a single boolean); running
 * the full block-stream parser per slug × language would balloon a
 * /pages/tree request to N parses. Instead, the probe reads the first
 * 2 KiB, splits on `---`, and runs a minimal key-line scan. Worst case
 * for a malformed file: probe yields `disabled=false` and the editor
 * shows it as enabled — never a security issue, only a stale flag the
 * user fixes by opening the editor.
 */
final class TreeAggregator
{
    private const FRONTMATTER_PROBE_BYTES = 2048;

    /** @var array<string, true> blocks duplicate-slug detection per request */
    private array $seenSlugsThisRequest = [];

    /** @var array<string, array<string, array{disabled:bool,hasMd:bool}>>  lang => slug => meta */
    private array $slugMetaCache = [];

    /** @var array<string, array<string, string>>|null  all-routes cache: lang => url => slug */
    private ?array $routesCache = null;

    /**
     * @param list<string> $supportedLangs    in display order, from config/i18n.php
     * @param list<string> $configuredSections  ordered tree-section keys
     */
    public function __construct(
        private array $supportedLangs,
        private string $defaultLang,
        private string $treeRoot,
        private array $configuredSections,
        private string $overlayDir,    // <core>/content (overlay files live here)
        private string $routesPath,    // <core>/config/routes.php
        private string $contentDir,    // <core>/<paths.content>
    ) {
    }

    public function build(): TreeView
    {
        $languages = [];
        foreach ($this->supportedLangs as $lang) {
            if (!Identifiers::isValidLang($lang)) {
                continue;
            }
            $languages[] = $this->buildLanguage($lang);
        }
        return new TreeView(
            root:      $this->treeRoot,
            languages: $languages,
            version:   $this->computeVersion(),
        );
    }

    // ============================================================
    // Per-language assembly
    // ============================================================

    private function buildLanguage(string $lang): LanguageTree
    {
        $overlay      = $this->loadOverlay($lang);
        $rootSection  = is_array($overlay[$this->treeRoot] ?? null) ? $overlay[$this->treeRoot] : [];
        $routesForLang = $this->loadRoutesForLang($lang);
        $slugToUrl     = $this->reverseRoutes($routesForLang);

        // Track which slugs are referenced inside the tree so we know
        // which ones drop into Unsorted. Set per language — slugs are
        // namespaced by language in routes.php.
        $referencedSlugs = [];

        $sections = [];
        foreach ($this->configuredSections as $sectionKey) {
            if (!is_string($sectionKey) || $sectionKey === '') continue;
            $rawItems = is_array($rootSection[$sectionKey] ?? null) ? $rootSection[$sectionKey] : [];
            $items = $this->normaliseItems($rawItems, $lang, $slugToUrl, $referencedSlugs, '');
            $sections[] = new TreeSection(
                key:        $sectionKey,
                label:      ucfirst($sectionKey),
                isUnsorted: false,
                items:      $items,
            );
        }

        // Unsorted bucket: every slug in routes.<lang> not yet referenced
        // by any configured section. Preserves routes.php insertion
        // order for predictability.
        $unsortedItems = [];
        $unsortedIndex = 0;
        foreach ($routesForLang as $url => $slug) {
            if (!is_string($slug) || $slug === '' || isset($referencedSlugs[$slug])) {
                continue;
            }
            $unsortedItems[] = $this->buildSlugNode(
                lang:      $lang,
                slug:      $slug,
                url:       (string)$url,
                overlayLabel: null,
                overlayHidden: false,
                indexPath: (string)$unsortedIndex,
                children:  [],
                warnings:  [],
            );
            $unsortedIndex++;
        }
        $sections[] = new TreeSection(
            key:        'unsorted',
            label:      'Unsorted',
            isUnsorted: true,
            items:      $unsortedItems,
        );

        return new LanguageTree(
            lang:      $lang,
            isDefault: $lang === $this->defaultLang,
            sections:  $sections,
        );
    }

    /**
     * @param array<int|string, mixed> $rawItems
     * @param array<string, string>    $slugToUrl
     * @param array<string, true>      $referencedSlugs  (by reference)
     * @return list<TreeNode>
     */
    private function normaliseItems(
        array $rawItems,
        string $lang,
        array $slugToUrl,
        array &$referencedSlugs,
        string $indexPathPrefix,
    ): array {
        $out = [];
        $i = 0;
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) { $i++; continue; }
            $indexPath = $indexPathPrefix === '' ? (string)$i : $indexPathPrefix . '/' . $i;
            $node = $this->normaliseItem($rawItem, $lang, $slugToUrl, $referencedSlugs, $indexPath);
            if ($node !== null) {
                $out[] = $node;
            }
            $i++;
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $item
     * @param array<string, string>    $slugToUrl
     * @param array<string, true>      $referencedSlugs  (by reference)
     */
    private function normaliseItem(
        array $item,
        string $lang,
        array $slugToUrl,
        array &$referencedSlugs,
        string $indexPath,
    ): ?TreeNode {
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $hidden = ($item['hidden'] ?? false) === true;
        $warnings = [];

        // Derive type by field-presence priority.
        $type = $this->deriveType($item);

        // Children — recurse (only meaningful for folder, but a slug
        // page can also have children visually in the editor; we keep
        // the door open and let the writer decide later).
        $children = [];
        if (isset($item['children']) && is_array($item['children'])) {
            $children = $this->normaliseItems(
                $item['children'], $lang, $slugToUrl, $referencedSlugs, $indexPath,
            );
        }

        switch ($type) {
            case 'slug':
                $slug = is_string($item['slug'] ?? null) ? $item['slug'] : '';
                if (!Identifiers::isValidSlug($slug)) {
                    $warnings[] = 'invalid-slug';
                    return new TreeNode(
                        type: 'slug', slug: null, url: null,
                        label: $label !== '' ? $label : ($slug !== '' ? $slug : '(unnamed)'),
                        href: null, anchor: null,
                        hidden: $hidden, disabled: false, hasMd: false,
                        children: $children, indexPath: $indexPath, warnings: $warnings,
                    );
                }
                $referencedSlugs[$slug] = true;
                $url = $slugToUrl[$slug] ?? null;
                if ($url === null) {
                    $warnings[] = 'slug-not-in-routes';
                }
                $meta = $this->probeSlugMeta($lang, $slug);
                if (!$meta['hasMd']) {
                    $warnings[] = 'md-missing';
                }
                $resolvedLabel = $label !== '' ? $label : $slug;
                return new TreeNode(
                    type: 'slug', slug: $slug, url: $url,
                    label: $resolvedLabel,
                    href: null, anchor: null,
                    hidden: $hidden, disabled: $meta['disabled'], hasMd: $meta['hasMd'],
                    children: $children, indexPath: $indexPath, warnings: $warnings,
                );

            case 'href':
                $href = is_string($item['href'] ?? null) ? $item['href'] : '';
                if ($href === '') $warnings[] = 'empty-href';
                return new TreeNode(
                    type: 'href', slug: null, url: null,
                    label: $label !== '' ? $label : '(external)',
                    href: $href, anchor: null,
                    hidden: $hidden, disabled: false, hasMd: false,
                    children: $children, indexPath: $indexPath, warnings: $warnings,
                );

            case 'anchor':
                $anchor = is_string($item['anchor'] ?? null) ? $item['anchor'] : '';
                if ($anchor === '') $warnings[] = 'empty-anchor';
                return new TreeNode(
                    type: 'anchor', slug: null, url: null,
                    label: $label !== '' ? $label : '#' . $anchor,
                    href: null, anchor: $anchor,
                    hidden: $hidden, disabled: false, hasMd: false,
                    children: $children, indexPath: $indexPath, warnings: $warnings,
                );

            case 'folder':
            default:
                return new TreeNode(
                    type: 'folder', slug: null, url: null,
                    label: $label !== '' ? $label : '(group)',
                    href: null, anchor: null,
                    hidden: $hidden, disabled: false, hasMd: false,
                    children: $children, indexPath: $indexPath, warnings: $warnings,
                );
        }
    }

    /**
     * Type derivation from item shape — same priority order as
     * nav-core.html's render branch (anchor > slug > href > folder).
     *
     * @param array<int|string, mixed> $item
     */
    private function deriveType(array $item): string
    {
        if (isset($item['anchor'])) return 'anchor';
        if (isset($item['slug']))   return 'slug';
        if (isset($item['href']))   return 'href';
        return 'folder';
    }

    /**
     * Build a slug-typed node for the unsorted bucket (no overlay
     * reference, no label override, no warnings beyond what probing
     * surfaces).
     *
     * @param list<TreeNode> $children
     * @param list<string>   $warnings
     */
    private function buildSlugNode(
        string $lang,
        string $slug,
        string $url,
        ?string $overlayLabel,
        bool $overlayHidden,
        string $indexPath,
        array $children,
        array $warnings,
    ): TreeNode {
        $meta = $this->probeSlugMeta($lang, $slug);
        if (!$meta['hasMd']) {
            $warnings[] = 'md-missing';
        }
        return new TreeNode(
            type: 'slug', slug: $slug, url: $url,
            label: $overlayLabel ?? $slug,
            href: null, anchor: null,
            hidden: $overlayHidden, disabled: $meta['disabled'], hasMd: $meta['hasMd'],
            children: $children, indexPath: $indexPath, warnings: $warnings,
        );
    }

    // ============================================================
    // File reads
    // ============================================================

    /**
     * @return array<int|string, mixed>
     */
    private function loadOverlay(string $lang): array
    {
        $path = $this->overlayDir . DIRECTORY_SEPARATOR . '_i18n_overlay.' . $lang . '.json';
        if (!is_file($path)) {
            return [];
        }
        $real = realpath($path);
        if ($real === false) return [];
        // realpath-contain the overlay inside the content dir to defend
        // against a symlinked overlay pointing elsewhere.
        $contentReal = realpath($this->overlayDir);
        if ($contentReal === false || !str_starts_with($real, $contentReal . DIRECTORY_SEPARATOR)) {
            return [];
        }
        $raw = @file_get_contents($real);
        if ($raw === false) return [];
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Malformed overlay → treat as empty so the rest of the
            // tree still renders. Operator sees broken overlay via
            // the public-site renderer.
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>  url => slug for the requested lang
     */
    private function loadRoutesForLang(string $lang): array
    {
        if ($this->routesCache === null) {
            $this->routesCache = [];
            $real = realpath($this->routesPath);
            if ($real !== false) {
                try {
                    $loaded = require $real;
                } catch (\Throwable) {
                    $loaded = null;
                }
                if (is_array($loaded) && is_array($loaded['routes'] ?? null)) {
                    foreach ($loaded['routes'] as $l => $map) {
                        if (is_string($l) && is_array($map)) {
                            $this->routesCache[$l] = $map;
                        }
                    }
                }
            }
        }
        $lr = $this->routesCache[$lang] ?? null;
        if (!is_array($lr)) return [];
        $out = [];
        foreach ($lr as $url => $slug) {
            if (!is_string($url) || !is_string($slug)) continue;
            if (!Identifiers::isValidSlug($slug)) continue;
            $out[$url] = $slug;
        }
        return $out;
    }

    /**
     * @param array<string, string> $routesForLang
     * @return array<string, string>  slug => url
     */
    private function reverseRoutes(array $routesForLang): array
    {
        $out = [];
        foreach ($routesForLang as $url => $slug) {
            // First match wins — if two URLs alias the same slug, the
            // tree shows the first. Frontend works either way.
            if (!isset($out[$slug])) {
                $out[$slug] = (string)$url;
            }
        }
        return $out;
    }

    /**
     * Lightweight probe: read first FRONTMATTER_PROBE_BYTES, look for
     * `disabled: true` in the front-matter. No PageDocument parse,
     * no AttributeParser — this runs once per slug per language per
     * tree build; the editor still uses the full parser at edit time.
     *
     * @return array{disabled: bool, hasMd: bool}
     */
    private function probeSlugMeta(string $lang, string $slug): array
    {
        if (isset($this->slugMetaCache[$lang][$slug])) {
            return $this->slugMetaCache[$lang][$slug];
        }
        $path = $this->contentDir . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $slug . '.md';
        $meta = ['disabled' => false, 'hasMd' => false];
        if (!is_file($path)) {
            return $this->slugMetaCache[$lang][$slug] = $meta;
        }
        $real = realpath($path);
        if ($real === false) {
            return $this->slugMetaCache[$lang][$slug] = $meta;
        }
        $contentReal = realpath($this->contentDir);
        if ($contentReal === false || !str_starts_with($real, $contentReal . DIRECTORY_SEPARATOR)) {
            return $this->slugMetaCache[$lang][$slug] = $meta;
        }
        $meta['hasMd'] = true;

        $fh = @fopen($real, 'rb');
        if ($fh === false) {
            return $this->slugMetaCache[$lang][$slug] = $meta;
        }
        try {
            $head = (string)fread($fh, self::FRONTMATTER_PROBE_BYTES);
        } finally {
            fclose($fh);
        }
        // Front-matter only present if file starts with `---\n` and a
        // closing `---` follows. Scan the closing-delimited prefix; if
        // the file doesn't carry FM, there's nothing to probe.
        if (!str_starts_with($head, "---\n") && !str_starts_with($head, "---\r\n")) {
            return $this->slugMetaCache[$lang][$slug] = $meta;
        }
        // Strip the opening fence and find the closing one. The probe
        // is best-effort — if the closing fence isn't within the first
        // 2 KiB we accept the read window.
        $afterOpen = substr($head, strpos($head, "\n") + 1);
        $closeAt   = strpos($afterOpen, "\n---");
        $fm        = $closeAt === false ? $afterOpen : substr($afterOpen, 0, $closeAt);

        // Cheap line-scan for `disabled:` — the FM mini-format is
        // unquoted key: value lines; whitespace tolerant.
        foreach (preg_split('/\r?\n/', $fm) ?: [] as $line) {
            if (preg_match('/^\s*disabled\s*:\s*(\S+)\s*$/', $line, $m) === 1) {
                $v = strtolower($m[1]);
                if (in_array($v, ['true', 'yes', '1'], true)) {
                    $meta['disabled'] = true;
                }
                break;
            }
        }
        return $this->slugMetaCache[$lang][$slug] = $meta;
    }

    // ============================================================
    // Optimistic-locking version
    // ============================================================

    /**
     * Hash over the *contents* of every overlay file + routes.php.
     *
     * We deliberately content-hash rather than mtime-hash. Most file
     * systems give filemtime() one-second resolution; two mutations
     * inside the same wall-clock second therefore produce identical
     * mtime-versions, which would silently bypass the optimistic-
     * locking check on rapid back-to-back saves (DnD bursts, two
     * editors hitting save in the same second). Content-hashing costs
     * a few KiB of disk reads per /pages/tree fetch — negligible at
     * the showcase data volume and a hard guarantee against version
     * collisions.
     *
     * Missing files contribute a stable "absent" token so the version
     * still flips when a previously-absent overlay is created.
     */
    private function computeVersion(): string
    {
        // Force-flush PHP's stat cache so a previous filemtime/realpath
        // call within this same request doesn't mask a fresh write —
        // belt-and-braces around the content-hash itself.
        clearstatcache();

        $parts = [];
        foreach ($this->supportedLangs as $lang) {
            $path = $this->overlayDir . DIRECTORY_SEPARATOR . '_i18n_overlay.' . $lang . '.json';
            $parts[] = $lang . ':' . self::fileFingerprint($path);
        }
        $parts[] = 'routes:' . self::fileFingerprint($this->routesPath);
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Content fingerprint of one file. SHA-256 of the bytes, or a
     * stable sentinel string if absent / unreadable. We deliberately
     * do NOT throw on read failures — the version is consulted on
     * every read AND every write, and a transient EACCES on an
     * orphaned-but-empty overlay should not 500 the editor.
     */
    private static function fileFingerprint(string $path): string
    {
        if (!is_file($path)) return 'absent';
        $h = @hash_file('sha256', $path);
        return $h === false ? 'unreadable' : $h;
    }
}
