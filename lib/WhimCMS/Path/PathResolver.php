<?php
declare(strict_types=1);

namespace H42\WhimCMS\Path;

use H42\WhimCMS\Config;
use H42\WhimCMS\Log;

/**
 * Resolves and validates the `config/app.php → paths` block to
 * absolute filesystem paths that the rest of the engine can consume.
 *
 * Lives under `Path\` together with the runtime-side `AssetPathResolver`
 * — both are concerned with turning string-shaped path inputs into
 * realpath-contained filesystem locations, just at different lifecycle
 * stages: this class runs once at boot for the configured app paths
 * (theme/, i18n/, content/, var/), the asset resolver runs per request
 * for individual `/assets/...` URLs.
 *
 * Responsibilities — boot-time path setup, fail-loud:
 *
 *   1. Read the `paths.*` config block.
 *   2. Validate each value against a strict allowlist (relative paths
 *      only, no `..`, no leading `/`, no control characters, segments
 *      matching `[a-zA-Z0-9._-]`). The single literal `.` is allowed
 *      to mean "rootDir itself" (BC default for paths.theme).
 *   3. Build absolute paths under rootDir.
 *   4. Run `ensureVarDir()` — create paths.var if missing, drop a
 *      `.whimcms-state` marker; refuse to adopt an existing-but-
 *      unmarked directory.
 *   5. Apply realpath() to every path and verify containment under
 *      rootDir (catches symlink escapes).
 *
 * Plus a separate helper for the optional config-driven log file
 * (`resolveOptionalLogFile`) — the path validator is the same.
 *
 * The class has no runtime state beyond rootDir. It exists to keep
 * the boot-time path concerns out of the Kernel's main dispatch
 * lifecycle.
 *
 * Security audit notes:
 * - Every path value goes through the strict regex BEFORE any
 *   filesystem operation. Path-traversal characters (`..`, leading
 *   `/`, NUL/CR/LF) are rejected up front.
 * - realpath() is the second gate: it canonicalises symlinks and the
 *   `str_starts_with(real, rootReal . DIR_SEP)` check refuses any
 *   path that actually resolves outside rootDir.
 * - ensureVarDir refuses to silently adopt a pre-existing directory.
 *   The marker file's existence is required as proof of WhimCMS
 *   ownership; an operator who legitimately wants to reuse a dir
 *   creates the marker themselves with a clear `touch` command.
 * - All failures throw \RuntimeException with descriptive messages.
 *   Boot-time loud-fail is the desired behaviour; silent fallback to
 *   defaults could mask config errors that lead to wrong paths
 *   being used.
 */
final class PathResolver
{
    private const MARKER_NAME = '.whimcms-state';

    public function __construct(private string $rootDir)
    {
    }

    /**
     * Run the full path-resolution pipeline. Returns the resolved
     * absolute paths plus the derived `themeUrl` URL fragment.
     *
     * Side effects:
     * - Creates paths.var if it doesn't exist (with `.whimcms-state`
     *   marker) — `ensureVarDir()` invocation.
     * - Throws on any validation or containment failure.
     *
     * @return array{theme:string, i18n:string, content:string, var:string, themeUrl:string}
     */
    public function resolve(): array
    {
        $abs = $this->buildAbsolutePaths();
        $this->ensureVarDir($abs['var']);
        return $this->realpathContain($abs);
    }

    /**
     * Validate the optional `log_file` config value and return its
     * absolute path under paths.var (creating the parent directory if
     * needed). Returns null when log_file is unset or empty.
     *
     * The caller (Kernel) installs the result via `Log::setFile()` —
     * keeps PathResolver from depending on Log.
     */
    public function resolveOptionalLogFile(string $varAbsPath): ?string
    {
        $logFile = Config::get('log_file');
        if (!is_string($logFile) || $logFile === '') {
            return null;
        }
        if (!self::isValidRelativePath($logFile)) {
            throw new \RuntimeException(
                "log_file must be a valid relative path under paths.var: '{$logFile}'"
            );
        }
        $abs = $varAbsPath . '/' . $logFile;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $abs;
    }

    /**
     * Strict allowlist for `paths.*` config values. Relative paths only,
     * no `..` segments, no leading `/`, no control characters. Each
     * segment is `[a-zA-Z0-9._-]+`. The single literal `.` is allowed
     * to mean "rootDir itself" (BC default for paths.theme).
     *
     * Public so the Kernel can reuse it for adjacent path validations
     * (e.g. `log_file`, also relative-under-rootDir).
     */
    public static function isValidRelativePath(string $value): bool
    {
        if ($value === '.') {
            return true;
        }
        if ($value === '' || str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) {
            return false;
        }
        if (str_starts_with($value, '/')) {
            return false;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $value) === 1) {
            return false;
        }
        return preg_match('#^[a-zA-Z0-9._-]+(/[a-zA-Z0-9._-]+)*$#', $value) === 1;
    }

    /**
     * Read paths.* from config, validate, build absolute paths against
     * rootDir. Returns paths as raw absolute strings — realpath
     * containment is enforced separately by realpathContain() AFTER
     * ensureVarDir() so the var directory can be created on first boot.
     *
     * Also derives `themeUrl`: the URL fragment that prefixes all
     * theme-served URLs (styles, js, assets). `""` when theme is at
     * rootDir; `"/theme"` (no trailing slash) when theme is in a
     * subfolder. Templates concatenate as `{{ BASE }}{{ THEME_URL }}/x`.
     *
     * @return array{theme:string, i18n:string, content:string, var:string, themeUrl:string}
     */
    private function buildAbsolutePaths(): array
    {
        $cfg = (array)Config::get('paths', []);
        $defaults = [
            'theme'   => '.',
            'i18n'    => 'i18n',
            'content' => 'content',
            'var'     => 'var',
        ];

        $resolved = [];
        foreach ($defaults as $key => $defaultValue) {
            $value = $cfg[$key] ?? $defaultValue;
            if (!is_string($value)) {
                throw new \RuntimeException("paths.{$key} must be a string.");
            }
            if (!self::isValidRelativePath($value)) {
                throw new \RuntimeException(
                    "paths.{$key} is not a valid relative path: '{$value}'. "
                    . "Allowlist: segments matching [a-zA-Z0-9._-] joined by /, "
                    . "no '..', no leading '/', no control characters. "
                    . "Use '.' to mean rootDir itself."
                );
            }
            $resolved[$key] = ($value === '.')
                ? $this->rootDir
                : $this->rootDir . '/' . $value;
        }

        $themeRel = (string)($cfg['theme'] ?? $defaults['theme']);
        $resolved['themeUrl'] = ($themeRel === '.') ? '' : '/' . $themeRel;

        return $resolved;
    }

    /**
     * Apply realpath() to each absolute path and verify containment
     * under rootDir. Catches symlink escapes and hands back canonical
     * paths so downstream comparisons are predictable.
     *
     * Run AFTER ensureVarDir() — paths['var'] may have been freshly
     * created by that step, so this is the first realpath that will
     * succeed for it.
     *
     * @param array{theme:string, i18n:string, content:string, var:string, themeUrl:string} $paths
     * @return array{theme:string, i18n:string, content:string, var:string, themeUrl:string}
     */
    private function realpathContain(array $paths): array
    {
        $rootReal = realpath($this->rootDir);
        if ($rootReal === false) {
            throw new \RuntimeException("rootDir does not resolve: {$this->rootDir}");
        }
        $out = ['themeUrl' => $paths['themeUrl']];
        foreach (['theme', 'i18n', 'content', 'var'] as $key) {
            $real = realpath($paths[$key]);
            if ($real === false) {
                throw new \RuntimeException(
                    "paths.{$key} does not resolve to an existing directory: {$paths[$key]}"
                );
            }
            if ($real !== $rootReal && !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException(
                    "paths.{$key} resolves outside rootDir: {$real}"
                );
            }
            $out[$key] = $real;
        }
        return $out;
    }

    /**
     * Verify the var directory either does not exist (create it with a
     * marker) or already carries our marker (was created by a previous
     * boot). Existing-but-unmarked directories are refused — operators
     * must explicitly claim them.
     *
     * The marker is a hidden file `.whimcms-state` containing the
     * creation timestamp. It serves no runtime purpose beyond
     * ownership identification.
     */
    private function ensureVarDir(string $varPath): void
    {
        $marker = $varPath . '/' . self::MARKER_NAME;
        if (is_dir($varPath)) {
            if (is_file($marker)) {
                return;  // already ours
            }
            throw new \RuntimeException(
                "paths.var points at an existing directory that was not created by WhimCMS: "
                . $varPath . ". Either configure paths.var to a non-existing path "
                . "(WhimCMS will create it on next boot), or, if this directory really is "
                . "WhimCMS state from a previous install, claim it manually with: "
                . "touch '{$marker}'"
            );
        }
        if (!@mkdir($varPath, 0700, true) && !is_dir($varPath)) {
            Log::lastPhpError('paths.var mkdir failed', ['path' => $varPath]);
            throw new \RuntimeException("Cannot create paths.var directory: {$varPath}");
        }
        @chmod($varPath, 0700);
        @file_put_contents(
            $marker,
            "WhimCMS state directory marker.\n"
            . "Created: " . gmdate('c') . "\n"
            . "Do not delete unless you intend to relinquish this directory.\n",
            LOCK_EX
        );
    }
}
