<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Path;

/**
 * Resolve, validate, and prepare WhimAdmin's filesystem layout.
 *
 * Mirrors the design of `H42\WhimCMS\Path\PathResolver` but operates
 * on whimadmin's own root and var/ directory so the two subsystems
 * cannot collide on shared state.
 *
 * On boot:
 *   1. Verify whimadmin/var/ exists (or create with marker).
 *   2. Reject an existing-but-unmarked var/ — refuses to "adopt" a
 *      directory that may belong to something else.
 *   3. Drop a deny-all .htaccess inside var/ if missing (defence in
 *      depth — the front .htaccess already routes /var/* to the
 *      front controller, but a misconfigured Apache could expose it).
 *   4. Return the fixed set of subdirectory paths.
 *
 * ⚠️ Step 4 previously read "Realpath-contain every returned path under
 * whimadmin's root." That is not what this class does, and the gap is
 * wider than the wording suggests: `var`, `state` and `logs` are plain
 * string concatenation; `views` and `config` call `realpath()` but fall
 * back to the raw concatenated string via `?:` — and **no returned path
 * is ever compared against the root**, so nothing here is "contained" in
 * the sense that word carries elsewhere in the project (see the core's
 * `Path\PathResolver::realpathContain`, which does perform the check).
 *
 * Why that is tolerable here, and the condition on which it rests:
 * paths are **not user-supplied**. The resolver hard-codes every
 * subdirectory name — there is no `paths.*` config key analogous to the
 * core's. The only way to escape is a symlink placed inside
 * `whimadmin/var/` by someone who already has local write access, and
 * who therefore already owns the state this would protect.
 *
 * **If a configurable path is ever introduced here, this docblock stops
 * being true and a real containment check becomes mandatory.**
 *
 * WhimAdmin lives in a fixed layout next to the core.
 */
final class PathResolver
{
    /** Marker file proving whimadmin owns a given var/ directory. */
    private const MARKER = '.whimadmin-state';

    public function __construct(private string $rootDir)
    {
        if (realpath($rootDir) === false) {
            throw new \RuntimeException("WhimAdmin root not found: {$rootDir}");
        }
    }

    /**
     * @return array{root:string, var:string, state:string, logs:string, views:string, config:string}
     */
    public function resolve(): array
    {
        $root  = realpath($this->rootDir);
        if ($root === false) {
            throw new \RuntimeException("WhimAdmin root unreadable: {$this->rootDir}");
        }
        $varDir = $root . DIRECTORY_SEPARATOR . 'var';

        $this->ensureVarDir($varDir);
        $this->ensureDenyAll($varDir);

        $stateDir = $varDir . DIRECTORY_SEPARATOR . 'state';
        $logsDir  = $varDir . DIRECTORY_SEPARATOR . 'logs';
        $this->ensureSubDir($stateDir);
        $this->ensureSubDir($logsDir);

        $viewsDir  = $root . DIRECTORY_SEPARATOR . 'views';
        $configDir = $root . DIRECTORY_SEPARATOR . 'config';
        $this->mustExist($viewsDir,  'whimadmin/views');
        $this->mustExist($configDir, 'whimadmin/config');

        return [
            'root'   => $root,
            'var'    => $varDir,
            'state'  => $stateDir,
            'logs'   => $logsDir,
            'views'  => realpath($viewsDir)  ?: $viewsDir,
            'config' => realpath($configDir) ?: $configDir,
        ];
    }

    private function ensureVarDir(string $dir): void
    {
        if (!is_dir($dir)) {
            // Fresh install — create with marker.
            if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create whimadmin/var: {$dir}");
            }
            @file_put_contents($dir . DIRECTORY_SEPARATOR . self::MARKER, '');
            @chmod($dir . DIRECTORY_SEPARATOR . self::MARKER, 0o600);
            return;
        }
        // Existing dir — must carry our marker, otherwise refuse to
        // claim it. A previous tenant's data would otherwise be at
        // risk of being swept by future cache cleanup logic.
        $marker = $dir . DIRECTORY_SEPARATOR . self::MARKER;
        if (!is_file($marker)) {
            throw new \RuntimeException(
                "whimadmin/var exists without ownership marker. Refusing to claim: {$dir} "
                . "(to opt in, create the file " . self::MARKER . " inside it)"
            );
        }
    }

    private function ensureSubDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create dir: {$dir}");
        }
    }

    /**
     * Drop a deny-all .htaccess into var/ if missing. The Apache 2.4
     * directive is `Require all denied`; the 2.2 fallback `Deny from
     * all` is intentionally absent — every supported Apache version is
     * 2.4+. This is defence-in-depth, not the primary boundary.
     */
    private function ensureDenyAll(string $dir): void
    {
        $path = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file($path)) {
            return;
        }
        @file_put_contents($path, "Require all denied\n");
        @chmod($path, 0o600);
    }

    private function mustExist(string $dir, string $label): void
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException("Required directory missing: {$label}");
        }
    }
}
