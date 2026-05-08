<?php
declare(strict_types=1);

namespace H42\WhimCMS\Path;

/**
 * Resolves a URL-style asset path (e.g. `/assets/photos/x.jpg`) to a
 * real filesystem path under one of the configured asset roots.
 *
 * Lives under `Path\` together with the boot-time `PathResolver` —
 * both turn string-shaped path inputs into realpath-contained
 * filesystem locations, just at different lifecycle stages.
 *
 * The reusable path-safety layer behind the `{% image %}` directive
 * and any other endpoint that needs to serve files from the project's
 * asset roots (PDFs, downloads, anything). Callers keep their own
 * domain logic (decompression-bomb caps, GD fallbacks, cache writes);
 * this class owns the **path safety** layer and nothing else.
 *
 * Multi-root behaviour:
 *   The URL pattern accepts ANY of the configured roots as the leading
 *   segment. realpath containment is checked against each root in turn
 *   — first match wins. If no root contains the resolved real path,
 *   the request is rejected.
 *
 * Security audit notes:
 *   - URL pattern check first: only `[A-Za-z0-9_./-]` after the root
 *     segment, ending in one of the caller-provided extensions. NUL,
 *     `..`, control chars never reach the filesystem layer.
 *   - realpath() is the second gate: canonicalises symlinks and
 *     verifies containment under at least one allowed root.
 *   - Asset roots are operator-controlled (config); regex characters
 *     in root names are preg_quoted as defence-in-depth.
 *   - Returning null on any failure (rather than throwing) keeps the
 *     caller's error-response path uniform — 404 for missing, 400 for
 *     malformed, etc.
 */
final class AssetPathResolver
{
    /**
     * @param string       $rootDir    Project root the asset roots are relative to.
     * @param list<string> $assetRoots Trimmed directory names without leading/trailing
     *                                 slashes (e.g. `'assets'`, `'theme/assets'`).
     *                                 Typically sourced from `config/images.php → allowed_roots`.
     */
    public function __construct(
        private string $rootDir,
        private array $assetRoots,
    ) {
    }

    /**
     * Resolve an asset URL path to a real filesystem path. Returns null
     * on any safety failure so callers can map to a clean 4xx response
     * without distinguishing the precise reason (defence-in-depth: not
     * leaking why something failed).
     *
     * @param string       $assetPath          URL-style with leading `/`
     *                                         (e.g. `/assets/photos/x.jpg`).
     * @param list<string> $allowedExtensions  Extension whitelist without the dot,
     *                                         case-insensitive (e.g. `['jpg', 'jpeg', 'png', 'webp', 'gif']`
     *                                         for images, `['pdf']` for documents).
     *                                         Empty list throws — the caller must declare what shapes it
     *                                         is willing to serve. Each extension must match
     *                                         `[A-Za-z0-9]+` so it can be safely embedded in the regex.
     */
    public function resolve(string $assetPath, array $allowedExtensions): ?string
    {
        if ($allowedExtensions === []) {
            throw new \InvalidArgumentException(
                'AssetPathResolver::resolve requires at least one allowed extension.'
            );
        }
        foreach ($allowedExtensions as $ext) {
            if (!is_string($ext) || preg_match('/^[A-Za-z0-9]+$/', $ext) !== 1) {
                throw new \InvalidArgumentException(
                    "AssetPathResolver: allowed extension '" . (is_string($ext) ? $ext : '<non-string>')
                    . "' is not a plain alphanumeric string."
                );
            }
        }
        if ($this->assetRoots === []) {
            return null;
        }

        // Build the URL-pattern alternation from the configured roots.
        // preg_quote sanitises any regex metacharacters in the root names
        // (none expected — roots are config-controlled and trimmed — but
        // defence in depth).
        $rootAlternation = implode('|', array_map(
            static fn(string $r): string => preg_quote($r, '#'),
            $this->assetRoots,
        ));
        $extAlternation = implode('|', array_map('strtolower', $allowedExtensions));

        $pattern = '#^/(?:' . $rootAlternation . ')/[A-Za-z0-9_./-]+\.(' . $extAlternation . ')$#i';
        if (!preg_match($pattern, $assetPath)) {
            return null;
        }
        if (str_contains($assetPath, '..') || str_contains($assetPath, "\0")) {
            return null;
        }

        // realpath the candidate. May fail if the file doesn't exist or
        // an intermediate path component is not accessible.
        $candidate = $this->rootDir . $assetPath;
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }

        // Containment check: $real must live under at least one of the
        // configured asset roots. First match wins. Symlink escapes are
        // caught here because realpath() resolved through them already.
        foreach ($this->assetRoots as $root) {
            $rootReal = realpath($this->rootDir . '/' . $root);
            if ($rootReal === false) {
                // This root is misconfigured (does not resolve on disk).
                // Skip — try the others. Misconfig of one root must not
                // block lookups against the others.
                continue;
            }
            if (str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        return null;
    }

    /**
     * Cheap URL-shape check, no filesystem access. Returns true when the
     * candidate matches the asset-URL pattern (allowed root + allowed
     * extension, no `..` or NUL) but the file may or may not exist.
     *
     * Useful when the caller needs to distinguish "the URL never made
     * sense" (no action) from "the URL was well-formed but the file is
     * gone now" (e.g. drop a cache entry that referenced it). resolve()
     * collapses both into a single null return, which is the right
     * default — only callers with a legitimate cleanup hook should peek
     * at the shape.
     *
     * @param list<string> $allowedExtensions See resolve() for shape rules.
     */
    public function matchesShape(string $assetPath, array $allowedExtensions): bool
    {
        if ($allowedExtensions === [] || $this->assetRoots === []) {
            return false;
        }
        if (str_contains($assetPath, '..') || str_contains($assetPath, "\0")) {
            return false;
        }
        $rootAlternation = implode('|', array_map(
            static fn(string $r): string => preg_quote($r, '#'),
            $this->assetRoots,
        ));
        $extAlternation = implode('|', array_map('strtolower', $allowedExtensions));
        $pattern = '#^/(?:' . $rootAlternation . ')/[A-Za-z0-9_./-]+\.(' . $extAlternation . ')$#i';
        return preg_match($pattern, $assetPath) === 1;
    }

    /**
     * The configured asset roots, exposed for callers that need to walk
     * the asset tree themselves (e.g. cache sweepers that build a live
     * set from disk). Read-only.
     *
     * @return list<string>
     */
    public function assetRoots(): array
    {
        return $this->assetRoots;
    }
}
