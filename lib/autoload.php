<?php
declare(strict_types=1);

/**
 * Tiny PSR-4-style autoloader for the H42\WhimCMS\ namespace.
 *
 *   H42\WhimCMS\Foo                    → lib/WhimCMS/Foo.php
 *   H42\WhimCMS\Template\Engine        → lib/WhimCMS/Template/Engine.php
 *   H42\WhimCMS\Template\Directives\If → lib/WhimCMS/Template/Directives/If.php
 *
 * No external dependencies, no Composer required. Path resolution is
 * fully deterministic and bound to lib/ — class names that escape via
 * "..", null bytes, or other shenanigans simply won't resolve to a file.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'H42\\')) {
        return;
    }
    $relative = substr($class, 4); // strip "H42\"
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
