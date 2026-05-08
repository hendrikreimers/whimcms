<?php
declare(strict_types=1);

/**
 * WhimAdmin — front controller.
 *
 * Loads the WhimCMS core autoloader (security primitives, mailer,
 * template engine — read-only consumed) and the WhimAdmin autoloader,
 * then hands off to the WhimAdmin Kernel.
 *
 * WhimAdmin requires the WhimCMS core to be present at ../lib. If the
 * core autoload is missing, fail loud — running without it would mean
 * no shared HMAC primitives, no mail transport, no template engine.
 */

$coreAutoload = dirname(__DIR__) . '/lib/autoload.php';
if (!is_file($coreAutoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "WhimAdmin requires the WhimCMS core at ../lib (autoload.php missing).\n";
    exit;
}
require $coreAutoload;
require __DIR__ . '/lib/WhimAdmin/autoload.php';

(new \H42\WhimAdmin\Kernel(__DIR__, dirname(__DIR__)))->run();
