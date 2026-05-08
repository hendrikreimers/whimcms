<?php
declare(strict_types=1);

/**
 * WhimCMS — front controller.
 *
 * Boot is intentionally tiny: register the autoloader, hand control to
 * the Kernel. All routing, error handling, rendering, and contact-form
 * orchestration lives in lib/WhimCMS/Kernel.php and the small set of
 * helper classes it composes.
 */

require __DIR__ . '/lib/autoload.php';

(new \H42\WhimCMS\Kernel(__DIR__))->run();
