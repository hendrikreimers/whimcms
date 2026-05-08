<?php
declare(strict_types=1);

namespace H42\WhimCMS;

/**
 * Hardened global error / exception / fatal handler.
 *
 * Installs three handlers via `install()`:
 *
 *   1. register_shutdown_function — last-chance net for PHP fatals
 *      (memory exhaustion, parse errors in include()d files, undefined
 *      functions in extensions). Fatals bypass set_exception_handler;
 *      this is our only path for them, so without it the SAPI would
 *      return an empty body and the browser would substitute its own
 *      generic error page.
 *
 *   2. set_exception_handler — for any \Throwable that escapes dispatch.
 *      Three failure modes we have seen in production and defend against:
 *        a. Output buffers from a partial render leaking into the error
 *           page. We discard every active buffer first.
 *        b. The handler itself throwing (handle 3 below converts notices
 *           into ErrorException, so a stray warning inside Log::error
 *           would kill the response mid-write). Wrapped in a defensive
 *           try/catch with a bare-bones fallback that uses no app code.
 *        c. Output not reaching the client because some FastCGI / PHP-FPM
 *           setups don't auto-flush on exception exit. Explicit flush()
 *           at the end forces it.
 *
 *   3. set_error_handler — converts E_NOTICE / E_WARNING into
 *      ErrorException so exceptional control flow stays uniform.
 *
 * Output bodies are text/html with htmlspecialchars-escaped contents so
 * no path or message text can be interpreted as markup. The diagnostic
 * `X-H42-Error` response header is gated on the debug flag — the H42
 * spelling is kept as light obfuscation: a generic vendor name is less
 * useful for fingerprinting than `X-WhimCMS-Error` would be.
 */
final class ErrorHandler
{
    public function __construct(private bool $debug)
    {
    }

    public function install(): void
    {
        register_shutdown_function([$this, 'handleShutdown']);
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
    }

    /** Last-chance fatal-error renderer. */
    public function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatal = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($err['type'], $fatal, true)) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            if ($this->debug) {
                header('X-H42-Error: fatal');
            }
        }
        $msg  = (string)($err['message'] ?? '');
        $file = (string)($err['file']    ?? '');
        $line = (int)   ($err['line']    ?? 0);
        $body = "500 — Fatal Error (caught by shutdown)\n"
              . str_repeat('─', 60) . "\n\n"
              . $msg . "\n\nin {$file}:{$line}\n";
        $payload = $this->debug
            ? '<pre style="font:13px/1.5 ui-monospace,monospace;padding:2rem;background:#0b0b0c;color:#e6e6e6;white-space:pre-wrap;">'
              . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
            : '<h1>500 — Internal Server Error</h1><p>The server hit a fatal error.</p>';
        echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>500</title><meta name=\"robots\" content=\"noindex,nofollow\"></head><body>{$payload}</body></html>";
        @flush();
    }

    /** Exception-path renderer. Defensive try/catch + bare-bones fallback. */
    public function handleException(\Throwable $e): void
    {
        try {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            Log::error('Unhandled exception: ' . $e->getMessage(), [
                'class' => $e::class,
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                if ($this->debug) {
                    // Diagnostic marker for debug builds. `curl -i` will
                    // surface this even if some SAPI / proxy layer drops
                    // the body downstream. Suppressed in production so
                    // the exception class isn't leaked for fingerprinting.
                    header('X-H42-Error: ' . preg_replace('/[^A-Za-z0-9\\\\_:-]/', '', $e::class));
                }
            }

            if ($this->debug) {
                $body  = "500 — Internal Server Error\n";
                $body .= str_repeat('─', 60) . "\n\n";
                $body .= $e::class . "\n";
                $body .= $e->getMessage() . "\n\n";
                $body .= 'in ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
                $body .= "Trace:\n" . $e->getTraceAsString() . "\n";
                if ($e->getPrevious() !== null) {
                    $body .= "\nCaused by:\n";
                    $body .= $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage() . "\n";
                }
                echo "<!doctype html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>500 — Server Error</title><meta name=\"robots\" content=\"noindex,nofollow\"></head>\n<body style=\"margin:0;padding:0;background:#0b0b0c;color:#e6e6e6;\">\n<pre style=\"font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;padding:2rem;white-space:pre-wrap;word-break:break-word;\">"
                   . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                   . "</pre>\n</body>\n</html>";
            } else {
                echo "<!doctype html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>500 — Server Error</title><meta name=\"robots\" content=\"noindex,nofollow\"></head>\n<body><h1>500 — Internal Server Error</h1><p>Sorry, something went wrong. Please try again in a moment.</p></body>\n</html>";
            }
        } catch (\Throwable $inner) {
            // Last-resort fallback: nothing in app code may run here.
            // error_log is the PHP built-in; echo writes raw bytes.
            @error_log('[WhimCMS] Exception handler failed: ' . $inner->getMessage());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>500</title></head><body><h1>500 — Internal Server Error</h1><p>The server hit an error and the diagnostic page could not be rendered. Check the PHP error log.</p></body></html>";
        }
        // Force the bytes onto the wire even if PHP-FPM / a wrapping
        // SAPI would otherwise discard them on exception exit.
        @flush();
    }

    /** Convert PHP notices/warnings into ErrorException so flow is uniform. */
    public function handleError(int $sev, string $msg, string $file, int $line): bool
    {
        if ((error_reporting() & $sev) === 0) {
            return false;
        }
        throw new \ErrorException($msg, 0, $sev, $file, $line);
    }
}
