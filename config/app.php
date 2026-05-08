<?php
declare(strict_types=1);

/**
 * Application-level switches: logging, debug, paths, and template
 * globals.
 *
 * Direct HTTP access to this file is denied by config/.htaccess; the
 * PHP runtime reads it via require during Config::loadDir().
 */

return [
    // =================================================================
    // LOGGING
    // =================================================================

    /**
     * One of: 'off' | 'error' | 'warn' | 'info' | 'debug'.
     * Output goes to PHP's error_log destination (host-controlled).
     */
    'log_level' => 'error',

    /**
     * Optional file path for an additional, project-local log mirror.
     *
     * `null` (default): the PHP `error_log()` destination is the only
     *   sink. That's what the host typically aggregates / rotates.
     *
     * string: relative path under `paths.var`, e.g. `'logs/whimcms.log'`.
     *   Every record that passes `log_level` gets written to BOTH the
     *   PHP error log AND this file. Useful when the host's error log
     *   has delay (next-day rotation, slow web UI) and you want
     *   `tail -f` convenience for live debugging.
     *
     * Security: only relative paths under `paths.var` are accepted.
     * The same allowlist regex that validates path values applies.
     * No rotation is built in — pair with logrotate / cron for
     * production.
     */
    'log_file' => null,

    // =================================================================
    // DEBUG
    // =================================================================

    /**
     * When true, unhandled errors render the actual exception class,
     * message, file, line, and stack trace in the 500 response. Also
     * emits an `X-H42-Cache: hit|miss|write-failed|no-content` header
     * on rendered pages so a `curl -I` reveals the cache state.
     *
     * Default OFF: a forgotten deploy step that left this ON would
     * leak server paths, class structure, and trace data to anyone
     * who can trigger an exception. The safe default keeps an
     * accidentally-deployed dev branch from oversharing.
     *
     * Flip to true ONLY for local-dev sessions or temporary
     * production diagnostics — and remember to flip back. The
     * setting is read once at boot, no live hot-swap.
     */
    'debug' => false,

    // =================================================================
    // PATHS
    // =================================================================

    /**
     * Filesystem layout. Every value is a path RELATIVE to the
     * project root (where index.php lives). Absolute paths are
     * forbidden — the resolver rejects anything starting with `/`,
     * containing `..`, or containing control characters. realpath
     * containment under rootDir is enforced after resolution.
     *
     * The defaults below preserve the historical "everything at root"
     * layout (templates/, i18n/, styles/, js/, assets/ all directly
     * under rootDir). Set `theme` to a folder name (e.g. `'theme'`)
     * to collapse the visible identity bits under one directory and
     * unlock the "swap one folder = swap visual" workflow.
     *
     * Each path is independently overridable. Common configurations:
     *
     *   Default (BC):
     *     'theme'   => '.',
     *     'i18n'    => 'i18n',
     *     'content' => 'content',
     *     'var'     => 'var',
     *
     *   Bundled showcase (this install ships with):
     *     'theme'   => 'theme',
     *     'i18n'    => 'theme/i18n',
     *     'content' => 'content',
     *     'var'     => 'var',
     *
     *   Real deployment (one theme, separate content):
     *     'theme'   => 'theme',
     *     'i18n'    => 'theme/i18n',
     *     'content' => 'content',
     *     'var'     => 'var',
     */
    'paths' => [
        /**
         * Umbrella for the visible identity bits. Holds:
         *   <theme>/templates/   — Engine root
         *   <theme>/styles/      — URL-served CSS
         *   <theme>/js/          — URL-served ES modules
         *   <theme>/assets/      — vector identity + (optionally) raster
         *
         * `'.'` = no theme folder, everything sits at root (BC default).
         * `'theme'` = collapsed under one folder.
         *
         * The bundled showcase ships with `'theme'` so the project root
         * stays uncluttered and a one-folder theme-swap is possible.
         */
        'theme'   => 'theme',

        /**
         * i18n JSON dictionary root. By convention sits inside the theme
         * (so a theme-swap takes its microcopy with it), but can be set
         * independently (`'i18n'`) to share dictionaries across themes.
         */
        'i18n'    => 'theme/i18n',

        /**
         * Page-composition Markdown root. Independent of theme so
         * content survives a theme swap. Rarely needs changing.
         */
        'content' => 'content',

        /**
         * Runtime state + caches (HMAC secret, captcha tokens,
         * rate-limit buckets, mail audit log, image cache, content
         * cache, optional logs).
         *
         * SECURITY: must be a relative path under rootDir. Absolute
         * paths are rejected — the .htaccess deny-all in this directory
         * is the project's defence layer, and moving outside the project
         * would weaken that contract.
         *
         * On boot, WhimCMS verifies the directory either does not exist
         * (will create it with a `.whimcms-state` marker) or already
         * has the marker (was created by a previous WhimCMS boot).
         * Existing-but-unmarked directories are refused — operators
         * must explicitly claim them by creating the marker.
         */
        'var'     => 'var',
    ],

    // =================================================================
    // TEMPLATE GLOBALS
    // =================================================================

    /**
     * Variables merged into every render context. UPPER_SNAKE_CASE.
     * Reference in templates as %FOO%.
     */
    'globals' => [
        /** Bump on each deploy to bust browser caches for assets. */
        'CACHE_BUSTER' => '1',
    ],
];
