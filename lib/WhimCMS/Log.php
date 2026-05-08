<?php
declare(strict_types=1);

namespace H42\WhimCMS;

/**
 * Thin level-filtered wrapper around PHP's error_log().
 *
 * Configuration lives in config/app.php:
 *   - `log_level`: minimum level — any record below is discarded.
 *   - `log_file`:  optional project-local mirror file. When set,
 *                  every record that passes the level filter is
 *                  written BOTH to PHP's error_log destination AND
 *                  appended to this file. Useful when the host's
 *                  error log has delay (next-day rotation, slow web
 *                  UI) and `tail -f` convenience matters during
 *                  debugging.
 *
 * Output goes to whatever the host has configured as the PHP error
 * log — set "error_log" in php.ini (or via .htaccess
 * `php_value error_log /path/to/file`) to redirect that destination.
 *
 * Records are formatted as: "[WhimCMS][LEVEL] message {context}".
 */
final class Log
{
    private const LEVELS = [
        'debug' => 10,
        'info'  => 20,
        'warn'  => 30,
        'error' => 40,
        'off'   => 100,
    ];

    private static int $threshold = self::LEVELS['error'];

    /**
     * Optional project-local mirror file. `null` = disabled (only
     * PHP error_log() destination is used). Set via setFile() once
     * during boot from `config/app.php → log_file`.
     */
    private static ?string $filePath = null;

    /**
     * Configure the minimum level. Unknown levels are coerced to 'off'
     * (silent) — the assumption is that misconfiguration shouldn't
     * accidentally leak debug noise into production logs.
     */
    public static function setLevel(string $level): void
    {
        self::$threshold = self::LEVELS[strtolower($level)] ?? self::LEVELS['off'];
    }

    /**
     * Configure an optional project-local mirror file. Pass null (or
     * skip the call entirely) to disable. The Kernel calls this once
     * during bootstrap when `log_file` is set in config.
     *
     * The path is trusted at this point — the Kernel has already
     * validated it against the strict allowlist regex (no .., no
     * leading /, no control characters) and resolved it under
     * paths.var. A failure to write at use time is silently
     * suppressed so a missing/unwritable path cannot block requests;
     * the PHP error_log destination still receives the record.
     */
    public static function setFile(?string $path): void
    {
        self::$filePath = $path;
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warn(string $message, array $context = []): void
    {
        self::write('warn', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /**
     * Augment a log record with the underlying PHP error captured by
     * `error_get_last()`. Companion to the `@`-suppressed FS calls
     * scattered through the codebase: at strategic call sites where
     * a failure-return would otherwise lose all diagnostic context,
     * call this immediately after the failed operation to surface
     * the actual reason ("Permission denied", "Disk quota exceeded",
     * "Read-only file system", etc.) into the log.
     *
     * No-op when `error_get_last()` returns null (clean slate).
     * Calls `error_clear_last()` after writing so a subsequent
     * lastPhpError() at a later site doesn't double-report this one.
     *
     * Severity is `warn` because the call site itself decides whether
     * the operation was fatal (caller throws / returns false) — this
     * helper is purely diagnostic.
     *
     * @param array<string, mixed> $context  call-site context (op, path, …)
     */
    public static function lastPhpError(string $message, array $context = []): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        self::warn($message . ': ' . ($err['message'] ?? '?'), $context + [
            'phpFile' => $err['file'] ?? '?',
            'phpLine' => $err['line'] ?? 0,
        ]);
        error_clear_last();
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $weight = self::LEVELS[$level] ?? 0;
        if ($weight < self::$threshold) {
            return;
        }
        // Defence-in-depth against log injection: strip CR/LF/NUL from
        // the message before it reaches error_log(). All current call
        // sites pass static strings or server-built values, but a future
        // caller that forgets and forwards raw user input would otherwise
        // be able to forge fake log lines. Context values get the same
        // treatment via json_encode (which escapes them).
        $message = strtr($message, ["\r" => ' ', "\n" => ' ', "\0" => '']);
        $line = '[WhimCMS][' . strtoupper($level) . '] ' . $message;
        if ($context !== []) {
            // Flatten via JSON for log-greppability. JSON_PARTIAL_OUTPUT_ON_ERROR
            // keeps malformed values from blocking the actual record.
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        error_log($line);
        // Optional project-local mirror. LOCK_EX serialises concurrent
        // writes from sibling worker processes. Failure suppressed —
        // PHP error_log already received the record above, so a write
        // miss here is degraded UX (no tailable file), not data loss.
        if (self::$filePath !== null) {
            @file_put_contents(self::$filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
