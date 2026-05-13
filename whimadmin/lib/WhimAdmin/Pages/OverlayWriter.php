<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

use H42\WhimCMS\Content\HrefSanitizer;
use H42\WhimCMS\Content\Identifiers;

/**
 * Atomic writer + strict-shape validator for `_i18n_overlay.<lang>.json`.
 *
 * Single class for the two operations the tree mutator needs:
 *
 *   read(lang)          — load and decode the overlay; missing file is
 *                         normalised to an empty array
 *   write(lang, data)   — validate the in-memory tree against the
 *                         shape allowlist + value sanitisers, then
 *                         atomic-rename a tempfile into place
 *
 * The validator is the *only* boundary between the editor's posted
 * data and the JSON file the public site re-renders from. Drift here
 * is a stored-XSS risk (a planted `href` reaches `{% safe_href: %}`
 * after a tree-write), so every leaf goes through the same allowlist
 * the public-site's safe_href directive enforces.
 *
 * Shape contract:
 *
 *   root key (e.g. "navigation")
 *     └─ section key (e.g. "main", "footer")  — string with
 *        kebab/snake-shape, depth-1 only
 *        └─ list of item objects
 *           ├─ label    : string (required, 1..200 bytes, no controls)
 *           ├─ hidden   : bool (optional)
 *           ├─ slug     : string matching Identifiers::SLUG_PATTERN (mutex
 *           │             with anchor / href; presence picks type)
 *           ├─ anchor   : string ^[A-Za-z][A-Za-z0-9_-]{0,63}$ (without #)
 *           ├─ href     : string passing HrefSanitizer::check (rejects
 *           │             javascript:, data:, vbscript:, etc.)
 *           └─ children : recursive list of item objects (depth ≤ 6)
 *
 * Items missing all three of slug/anchor/href and having children
 * default to type=folder. Items with none of those four are rejected
 * — silent acceptance of an empty stub would let the editor build
 * unreachable nav entries.
 *
 * The writer regenerates the entire file on every save; the per-
 * language overlay is small (a few KiB at most) so this is cheaper
 * than a structural merge. Top-level keys outside the page-tree
 * root are preserved verbatim if they pass the same-key allowlist
 * for the overlay — the page-tree never touches unrelated overlay
 * sections (e.g. a future `footer.copy` key) on save.
 */
final class OverlayWriter
{
    private const FILENAME_FMT = '_i18n_overlay.%s.json';

    private const MAX_LABEL_BYTES   = 200;
    private const MAX_HREF_BYTES    = 2048;
    private const MAX_ANCHOR_BYTES  = 64;
    private const MAX_DEPTH         = 6;
    private const MAX_ITEMS_PER_SECTION = 200;

    private const ANCHOR_PATTERN  = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';
    private const SECTION_PATTERN = '/^[a-z][a-z0-9_-]{0,40}$/';

    public function __construct(
        private string $overlayDir,   // <core>/content
        private string $contentRealDir, // realpath of overlayDir, for containment
    ) {
    }

    /**
     * @return array<int|string, mixed> decoded overlay (empty if missing or unreadable)
     */
    public function read(string $lang): array
    {
        Identifiers::assertLang($lang);
        $path = $this->pathFor($lang);
        if (!is_file($path)) {
            return [];
        }
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            return [];
        }
        $raw = @file_get_contents($real);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Validate + atomically write the overlay for one language.
     *
     * @param array<int|string, mixed> $data    full overlay payload (root + sections)
     * @param string                   $treeRoot  page-tree root key (e.g. "navigation")
     * @param list<string>             $allowedOverlaySections  allowlist from i18n_overlay.allowed_sections
     */
    public function write(string $lang, array $data, string $treeRoot, array $allowedOverlaySections): void
    {
        Identifiers::assertLang($lang);
        $this->validate($data, $treeRoot, $allowedOverlaySections);

        $path = $this->pathFor($lang);
        $body = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        // Round-trip integrity: re-decode the bytes we're about to
        // persist. Catches a serialiser regression before disk write.
        try {
            $roundTrip = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Overlay re-decode failed: ' . $e->getMessage());
        }
        if ($roundTrip !== $data) {
            throw new \RuntimeException('Overlay serialiser produced a non-round-tripping value.');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write overlay (tempfile): {$path}");
        }
        @chmod($tmp, 0o644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot finalise overlay: {$path}");
        }
    }

    /**
     * Validation: hard-fail on any structural drift or unsafe value.
     *
     * @param array<int|string, mixed> $data
     * @param list<string> $allowedOverlaySections
     */
    public function validate(array $data, string $treeRoot, array $allowedOverlaySections): void
    {
        // Every top-level key must be in the overlay allowlist —
        // mirrors the public-site loader's filter so the editor cannot
        // persist a key the renderer would silently drop.
        foreach (array_keys($data) as $k) {
            if (!is_string($k) || !in_array($k, $allowedOverlaySections, true)) {
                throw new \RuntimeException(
                    "Overlay top-level key '" . (string)$k . "' is not in allowed_sections."
                );
            }
        }

        // Tree-root section: must be a map of section-key → list-of-items.
        if (isset($data[$treeRoot])) {
            $rootValue = $data[$treeRoot];
            if (!is_array($rootValue)) {
                throw new \RuntimeException("Overlay '{$treeRoot}' must be an object.");
            }
            foreach ($rootValue as $sectionKey => $sectionItems) {
                if (!is_string($sectionKey) || preg_match(self::SECTION_PATTERN, $sectionKey) !== 1) {
                    throw new \RuntimeException(
                        "Bad section key '" . (string)$sectionKey . "' in overlay '{$treeRoot}'."
                    );
                }
                if (!is_array($sectionItems)) {
                    throw new \RuntimeException(
                        "Overlay '{$treeRoot}.{$sectionKey}' must be a list."
                    );
                }
                if (!self::isList($sectionItems)) {
                    throw new \RuntimeException(
                        "Overlay '{$treeRoot}.{$sectionKey}' must be a zero-indexed list."
                    );
                }
                if (count($sectionItems) > self::MAX_ITEMS_PER_SECTION) {
                    throw new \RuntimeException(
                        "Overlay section '{$treeRoot}.{$sectionKey}' exceeds maximum item count of "
                        . self::MAX_ITEMS_PER_SECTION . '.'
                    );
                }
                foreach ($sectionItems as $idx => $item) {
                    if (!is_array($item)) {
                        throw new \RuntimeException(
                            "Overlay '{$treeRoot}.{$sectionKey}[{$idx}]' must be an object."
                        );
                    }
                    $this->validateItem($item, "{$treeRoot}.{$sectionKey}[{$idx}]", 0);
                }
            }
        }

        // Non-tree-root keys (e.g. a hypothetical `footer.copy`) are
        // accepted as-is — out of scope for the page-tree writer. The
        // loader's allowlist already gates them; deeper validation
        // happens at their own write site if any exists.
    }

    /**
     * @param array<int|string, mixed> $item
     */
    private function validateItem(array $item, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException("Overlay item '{$path}' exceeds maximum depth " . self::MAX_DEPTH . '.');
        }

        $allowedKeys = ['label', 'hidden', 'slug', 'anchor', 'href', 'children'];
        foreach (array_keys($item) as $k) {
            if (!is_string($k) || !in_array($k, $allowedKeys, true)) {
                throw new \RuntimeException("Overlay item '{$path}' has unknown key '" . (string)$k . "'.");
            }
        }

        // label — required, scalar string with control-byte reject.
        $label = $item['label'] ?? null;
        if (!is_string($label) || $label === '') {
            throw new \RuntimeException("Overlay item '{$path}' missing required 'label'.");
        }
        if (strlen($label) > self::MAX_LABEL_BYTES) {
            throw new \RuntimeException(
                "Overlay item '{$path}' label exceeds " . self::MAX_LABEL_BYTES . ' bytes.'
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            throw new \RuntimeException("Overlay item '{$path}' label contains control bytes.");
        }
        if (preg_match('//u', $label) !== 1) {
            throw new \RuntimeException("Overlay item '{$path}' label is not valid UTF-8.");
        }

        // hidden — optional, must be boolean if present.
        if (array_key_exists('hidden', $item) && !is_bool($item['hidden'])) {
            throw new \RuntimeException("Overlay item '{$path}' 'hidden' must be boolean.");
        }

        // type-discriminator fields — mutually exclusive between
        // slug/anchor/href (children may coexist with any of those or
        // stand alone for type=folder).
        $hasSlug   = array_key_exists('slug',   $item);
        $hasAnchor = array_key_exists('anchor', $item);
        $hasHref   = array_key_exists('href',   $item);
        $hasChildren = array_key_exists('children', $item);
        $exclusive = (int)$hasSlug + (int)$hasAnchor + (int)$hasHref;
        if ($exclusive > 1) {
            throw new \RuntimeException(
                "Overlay item '{$path}' has more than one of slug/anchor/href (mutually exclusive)."
            );
        }
        if ($exclusive === 0 && !$hasChildren) {
            throw new \RuntimeException(
                "Overlay item '{$path}' must have one of slug/anchor/href, or 'children' for a folder."
            );
        }

        if ($hasSlug) {
            $s = $item['slug'];
            if (!is_string($s) || !Identifiers::isValidSlug($s)) {
                throw new \RuntimeException("Overlay item '{$path}' has invalid 'slug'.");
            }
        }
        if ($hasAnchor) {
            $a = $item['anchor'];
            if (!is_string($a) || strlen($a) > self::MAX_ANCHOR_BYTES
                || preg_match(self::ANCHOR_PATTERN, $a) !== 1) {
                throw new \RuntimeException("Overlay item '{$path}' has invalid 'anchor'.");
            }
        }
        if ($hasHref) {
            $h = $item['href'];
            if (!is_string($h)) {
                throw new \RuntimeException("Overlay item '{$path}' 'href' must be a string.");
            }
            if (strlen($h) > self::MAX_HREF_BYTES) {
                throw new \RuntimeException("Overlay item '{$path}' 'href' exceeds maximum length.");
            }
            // Overlay hrefs may carry the path markers `~/...` (lang-
            // aware) and `^/...` (base-aware). Those are resolved by
            // I18n::load AFTER merge, BEFORE the public-site template
            // emits them through safe_href — so the raw value here
            // legitimately starts with `~` or `^`. Validate by stripping
            // the marker and re-running the allowlist against the
            // expanded `/...` form (which is what the renderer will
            // ultimately produce).
            if (!self::checkOverlayHref($h)) {
                throw new \RuntimeException("Overlay item '{$path}' 'href' rejected by URL allowlist.");
            }
        }

        if ($hasChildren) {
            $children = $item['children'];
            if (!is_array($children) || !self::isList($children)) {
                throw new \RuntimeException("Overlay item '{$path}' 'children' must be a list.");
            }
            if (count($children) > self::MAX_ITEMS_PER_SECTION) {
                throw new \RuntimeException(
                    "Overlay item '{$path}' children exceeds maximum count of " . self::MAX_ITEMS_PER_SECTION . '.'
                );
            }
            foreach ($children as $i => $child) {
                if (!is_array($child)) {
                    throw new \RuntimeException("Overlay item '{$path}.children[{$i}]' must be an object.");
                }
                $this->validateItem($child, "{$path}.children[{$i}]", $depth + 1);
            }
        }
    }

    private function pathFor(string $lang): string
    {
        return $this->overlayDir . DIRECTORY_SEPARATOR . sprintf(self::FILENAME_FMT, $lang);
    }

    /**
     * Overlay-href allowlist. Same scheme set as HrefSanitizer::check
     * plus the two path-marker prefixes `~/` and `^/` (validated by
     * checking the resolved `/...` form).
     */
    private static function checkOverlayHref(string $href): bool
    {
        if ($href === '' || strlen($href) > self::MAX_HREF_BYTES) {
            return false;
        }
        if (str_starts_with($href, '~/') || str_starts_with($href, '^/')) {
            // substr($href, 1) yields '/...' — re-run the strict allowlist
            // against the resolved-form prefix.
            return HrefSanitizer::check(substr($href, 1)) !== null;
        }
        return HrefSanitizer::check($href) !== null;
    }

    /** @param array<int|string, mixed> $a */
    private static function isList(array $a): bool
    {
        $i = 0;
        foreach (array_keys($a) as $k) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }
}
