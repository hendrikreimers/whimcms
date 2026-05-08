<?php
declare(strict_types=1);

/**
 * Tiny PSR-4 autoloader for the H42\WhimAdmin\ namespace.
 *
 *   H42\WhimAdmin\Foo                    → lib/WhimAdmin/Foo.php
 *   H42\WhimAdmin\Auth\Session           → lib/WhimAdmin/Auth/Session.php
 *
 * Symmetric to the WhimCMS core autoloader. Path resolution is fully
 * deterministic and bound to lib/WhimAdmin/ — class names that escape
 * via "..", null bytes, or other shenanigans simply won't resolve to
 * a file.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'H42\\WhimAdmin\\')) {
        return;
    }
    $relative = substr($class, 14); // strip "H42\WhimAdmin\"
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
