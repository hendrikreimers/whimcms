<?php
declare(strict_types=1);

/**
 * WhimAdmin runtime configuration.
 *
 * Direct HTTP access denied by config/.htaccess; loaded only by
 * H42\WhimAdmin\Config at request boot. This file is the single
 * source of truth for whimadmin's tunables — no `define()`s, no
 * `getenv()` reads scattered through the codebase.
 */

return [
    // =================================================================
    // DEBUG / LOGGING
    // =================================================================

    /**
     * When true, unhandled exceptions render a stack trace in the 500
     * response. **Default false** — admin endpoints are an attractive
     * target and a leaked trace exposes filesystem layout, class
     * structure, and (with bad config) credentials. Flip ON only for
     * local-dev diagnostic sessions; flip OFF before deployment.
     */
    'debug' => false,

    /**
     * One of: 'off' | 'error' | 'warn' | 'info' | 'debug'.
     * Reuses the WhimCMS core logger; output goes to PHP's error_log
     * destination plus the audit log file under whimadmin/var/logs/.
     */
    'log_level' => 'error',

    // =================================================================
    // AUTH — SESSION
    // =================================================================

    'session' => [
        /**
         * Cookie name carrying the signed session id. Must not collide
         * with the public-site cookie surface — none today, but the
         * `whimadmin_` prefix keeps that contract explicit.
         */
        'cookie_name' => 'whimadmin_sid',

        /**
         * Idle-timeout in seconds. Activity (any authenticated request)
         * resets the timer. Default 30 min — long enough for normal
         * editing, short enough that an unattended laptop locks itself
         * out by lunch.
         */
        'idle_seconds' => 1800,

        /**
         * Hard absolute lifetime regardless of activity. Default 8 h.
         * Forces a re-login at least once per work day.
         */
        'absolute_seconds' => 28800,

        /**
         * Bind the session to (ip + ua), (ua only), or none.
         * Same vocabulary as core's csrf.bind_strategy. Default 'ip_ua'
         * — strictest, sensible for a single-user admin where mobile-
         * roaming false-positives are not a concern.
         */
        'bind_strategy' => 'ip_ua',
    ],

    // =================================================================
    // AUTH — OTP (Email-delivered one-time code)
    // =================================================================

    'otp' => [
        /**
         * Code TTL in seconds. Default 5 min. The mail roundtrip plus
         * user-attention typically lands inside 2 min; 5 min is the
         * forgiving default. Going much higher reduces the security
         * gain of one-time-code-as-2nd-factor.
         */
        'ttl_seconds' => 300,

        /**
         * How many guesses are allowed before the code is invalidated
         * (forcing a fresh login round). Default 5. 6-digit code → 1M
         * codespace; 5 attempts gives ~5e-6 brute-force probability,
         * which together with the per-IP rate limiter is well below
         * any practical threat threshold.
         */
        'max_attempts' => 5,

        /**
         * Number of digits in the code. 6 is the conventional choice
         * (matches authenticator apps users are used to).
         */
        'digits' => 6,

        /**
         * Hard cap on OTP mails per UTC day for THIS install. Independent
         * of the core's `mail.daily_max` (which protects the contact-
         * form pipeline) so a noisy contact form cannot lock out admin
         * login mid-day, and a login-spammer flooding /login cannot
         * drain the host's per-day mail quota that the public site
         * relies on.
         *
         * Default 50 — far above any realistic single-operator volume
         * (admin mails one OTP per login, login is rate-limited at 5
         * attempts per 5 min per IP). Set to 0 to disable the cap
         * entirely (not recommended on shared hosting).
         */
        'daily_max' => 50,
    ],

    // =================================================================
    // AUTH — RATE LIMITING (per-IP, applied to login + OTP submit)
    // =================================================================

    'rate_limit' => [
        /**
         * Sliding window size in seconds, and max attempts per window.
         * Defaults: 5 attempts per 5 minutes per IP. Triggers a 429
         * response; a bot trying to brute-force is throttled to a
         * pace below useful.
         */
        'window_seconds' => 300,
        'max_attempts'   => 5,
    ],

    // =================================================================
    // AUTH — SETUP TOKEN
    // =================================================================

    'setup' => [
        /**
         * Setup-token TTL in seconds. Default 30 min — the operator who
         * just deployed WhimAdmin and is reading INSTALL.md is right
         * there at the keyboard; 30 min covers normal "find the token
         * in the sidecar file, paste, set password" flow with margin.
         * Short window means abandoned tokens auto-expire fast and the
         * plaintext-sidecar persistence window is bounded.
         *
         * Allowed range (Config::validate): 300..86400×30 — anything
         * outside fails loud at boot.
         */
        'token_ttl_seconds' => 1800,
    ],

    // =================================================================
    // CONTENT EDITING
    // =================================================================

    'content' => [
        /**
         * History depth: the soft-recycler keeps this many previous
         * versions per content file before pruning oldest. Default 10.
         * Set to 0 to disable history (still soft-deletes on remove,
         * just no per-save snapshots).
         */
        'history_max' => 10,
    ],

    // =================================================================
    // RECYCLER AUTO-SWEEP
    // =================================================================

    /**
     * The page-recycler, page-history, and asset-recycler trees grow
     * forever unless cleaned up. The sweeper runs on authed backend
     * access (after login) and ages out entries older than the
     * configured window. Sentinel-gated so it costs ~one `filemtime`
     * call per admin request.
     *
     *   sweep_interval_seconds   how often to even consider sweeping
     *                            0 = disable auto-sweep entirely
     *   content_max_age_days     page-recycler + history snapshot retention
     *                            0 = delete every entry on each sweep
     *                            36500 = effectively keep forever
     *   assets_max_age_days      assets-recycler retention (same scale)
     */
    'recycler' => [
        'sweep_interval_seconds' => 86400,
        'content_max_age_days'   => 30,
        'assets_max_age_days'    => 30,
    ],

    // =================================================================
    // MAIL OVERRIDES
    // =================================================================

    'mail' => [
        /**
         * Subject prefix on outbound admin mails (OTP codes, audit
         * notifications). Distinguishes admin mail from public-site
         * contact-form mail in the recipient's inbox.
         */
        'subject_prefix' => '[WhimAdmin] ',

        /**
         * Sender display name. Envelope `from` and SPF/DMARC alignment
         * are inherited from the core `config/mail.php`'s `from`
         * address — admin mail rides the same sending identity as the
         * site's contact form.
         */
        'from_name' => 'WhimAdmin',
    ],
];
