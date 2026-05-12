<?php
declare(strict_types=1);

namespace H42\WhimCMS;

/**
 * Editor-managed overlay for the i18n dictionary.
 *
 * Reads `content/_i18n_overlay.<lang>.json` and returns the subset
 * whose top-level keys pass an allowlist. The caller (I18n::load)
 * resolves path markers and deep-merges the result on top of the
 * developer-provided base dictionary.
 *
 * The split is deliberate:
 *
 *   - **I18n** owns "what the dictionary looks like" — file location
 *     for the base dict, the `~/x` and `^/x` path resolution, caching,
 *     and the final merge step.
 *   - **I18nOverlay** owns "what the editor is allowed to contribute"
 *     — file location for the overlay, JSON parsing, and the
 *     security-critical allowlist filter.
 *
 * Keeping the allowlist filter in its own auditable class makes the
 * security boundary visible at a glance. Anything in the overlay
 * whose top-level key isn't on the allowlist is silently dropped
 * here, before the merger ever sees it. An editor cannot, for
 * example, overwrite `errors._404.title`, `a11y.skipLink`, or
 * `home.contact.form.errors.required` even by trying — those keys
 * never make it past this gate.
 *
 * Failure modes:
 *
 *   - Missing overlay file → return null. Base dictionary is used
 *     as-is. This is the normal state for deployments that haven't
 *     opted into editor-driven nav / footer.
 *   - Unreadable file (permissions, mid-flight rename) → throws.
 *     A configured but unreadable overlay is an operator error, not
 *     a graceful-degradation case.
 *   - Invalid JSON → throws. Editor mistakes fail loud so the
 *     operator notices on the next reload, instead of seeing a
 *     half-merged nav silently.
 *   - Top-level not an object → return null. Same posture as a
 *     missing file; an editor that wrote `[]` at the root rather
 *     than `{}` shouldn't bring the site down.
 *
 * Format conventions (documented in CONTENT.md):
 *
 *   - Plain JSON. No comments (use a sibling .md if documentation
 *     is needed for the editor).
 *   - Path markers `~/x` (language-aware) and `^/x` (language-
 *     independent) work identically to the base i18n JSON; the
 *     caller (I18n) resolves them after merge so the same rules
 *     apply to overlay-provided values.
 */
final class I18nOverlay
{
    /**
     * Load and filter the overlay file for $lang, if it exists.
     *
     * Returns the surviving subset (top-level keys ∈ $allowedSections)
     * or null when there is nothing to merge (file absent, file
     * empty, root not an object).
     *
     * @param list<string> $allowedSections  Top-level keys the editor
     *                                       may contribute. Anything
     *                                       else is silently dropped.
     * @return array<string, mixed>|null
     */
    public static function load(string $lang, string $contentDir, array $allowedSections): ?array
    {
        $contentReal = realpath($contentDir);
        if ($contentReal === false) {
            return null;
        }
        $path = $contentReal . DIRECTORY_SEPARATOR . '_i18n_overlay.' . $lang . '.json';
        if (!is_file($path)) {
            return null;
        }
        $real = realpath($path);
        // Realpath containment: defense-in-depth. The overlay must
        // resolve to a file directly under $contentDir. A symlink
        // pointing outside the content root would be rejected — the
        // same posture the base i18n loader takes against its own
        // root.
        if ($real === false || !str_starts_with($real, $contentReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Overlay file not in content root: {$lang}");
        }

        $raw = @file_get_contents($real);
        if ($raw === false) {
            throw new \RuntimeException("Overlay file not readable: {$lang}");
        }
        // Empty file → nothing to merge. Treat as absent so an editor
        // who saved an empty file by accident doesn't see a hard fail.
        if (trim($raw) === '') {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON in overlay file: {$lang} ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($data)) {
            return null;
        }

        // Allowlist filter — the security boundary. Anything not on
        // the list is dropped silently: logging would create noise
        // from harmless editor typos, and an attacker who can write
        // arbitrary keys here can also just write a `nav` key, so
        // logging the off-list keys doesn't add real audit value.
        $filtered = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedSections, true)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered === [] ? null : $filtered;
    }

    /**
     * Deep-merge $overlay onto $base, returning a new array.
     *
     * Semantics:
     *
     *   - Both sides are associative arrays → recurse per key.
     *     This is how `nav.primary` and `meta.about` survive in
     *     parallel: the overlay's `meta` subtree merges with the
     *     base's `meta`, not the other way around.
     *
     *   - Either side is a sequential list (per `array_is_list`) →
     *     overlay value wholly replaces base value. A list is an
     *     ordered identity — merging two lists by index almost
     *     never produces what the author meant. So when the overlay
     *     says `nav.primary: [...]`, the base's `nav.primary` (if
     *     any) is dropped and the overlay's list is used as-is.
     *
     *   - Anything else (scalars, mixed types, overlay key absent
     *     in base) → overlay value replaces base value or is added
     *     fresh.
     *
     * Empty arrays count as lists by `array_is_list([])`, so an
     * overlay value of `[]` replaces rather than merges — the
     * intuitive thing for the cases that come up in practice
     * (clearing a nav menu, blanking a meta override).
     */
    public static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $hasBase = array_key_exists($key, $base);
            if (
                $hasBase
                && is_array($base[$key])
                && is_array($value)
                && !array_is_list($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }
}
