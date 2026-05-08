<?php
declare(strict_types=1);

namespace H42\WhimAdmin;

/**
 * Debug-aware error/exception/shutdown handlers for WhimAdmin.
 *
 * Mirrors the WhimCMS core's ErrorHandler design but uses whimadmin's
 * own debug flag — operator can set `debug=true` in `whimadmin/config/
 * app.php` to surface stack traces in 500 responses without affecting
 * the public-site setting.
 *
 * Three handlers wired up by `install()`:
 *
 *   - `set_exception_handler`  — catches uncaught Throwables, renders
 *                                a 500 response (trace if debug, plain
 *                                page otherwise), logs class+message
 *                                to PHP's error_log.
 *   - `set_error_handler`      — promotes E_WARNING / E_NOTICE / etc.
 *                                to ErrorException so they can't slip
 *                                through silently. Respects the `@`
 *                                operator (returns false when
 *                                error_reporting is suppressed).
 *   - `register_shutdown_function` — final-fallback for fatal errors
 *                                that bypass the above (E_ERROR,
 *                                E_PARSE, E_CORE_ERROR, …).
 *
 * Stack-trace exposure: only when `$debug === true`, and only into
 * the response body — the error_log line is the same in both modes
 * (class + message + file:line, never the trace) so production logs
 * stay hygienic even on a noisy day.
 */
final class ErrorHandler
{
    public function __construct(private bool $debug)
    {
    }

    public function install(): void
    {
        $debug    = $this->debug;
        $renderer = static function (\Throwable $e) use ($debug): void {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
            }
            if ($debug) {
                echo '<!doctype html><meta charset="utf-8"><title>WhimAdmin · 500</title>';
                echo '<pre>' . htmlspecialchars((string)$e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            } else {
                echo '<!doctype html><meta charset="utf-8"><title>WhimAdmin · 500</title><h1>500 — Internal error</h1>';
            }
            // Always log SHORT info — never the trace, which can echo
            // local variables (incl. secrets) bound on the call stack.
            \error_log('[WhimAdmin] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        };

        \set_exception_handler($renderer);

        \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect the `@` operator: when error_reporting is
            // suppressed, fall through to the default handler.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        \register_shutdown_function(static function () use ($renderer): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            $renderer(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
        });
    }
}
