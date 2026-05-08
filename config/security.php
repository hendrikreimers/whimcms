<?php
declare(strict_types=1);

/**
 * Anti-abuse pipeline knobs: CSRF/timing token, proof-of-work captcha,
 * per-IP rate limit, and the soft blocklist that a few bad submissions
 * earn a client.
 *
 * Each section is independent: tightening one without touching the
 * others is fine.
 */

return [
    // =================================================================
    // CSRF + FORM TIMING (HMAC token)
    // =================================================================

    'csrf' => [
        /** Form must be submitted at least this many seconds after render (anti-bot). */
        'min_age_seconds' => 3,
        /** Form is rejected after this many seconds (anti-replay). */
        'max_age_seconds' => 3600,

        /**
         * How tightly the CSRF / form-timing token is bound to the client.
         * See H42\WhimCMS\Csrf::deriveBindKey for the full semantics.
         *
         *   'ip_ua'  Default. Bind to IP + UA. Catches replay across
         *            networks AND across browsers. The mobile-IP-roaming
         *            false-positive (visitor changes network mid-form,
         *            sees "session expired") is the cost of admission;
         *            in practice rare enough to accept by default.
         *            Behind a CDN where REMOTE_ADDR is the edge IP, this
         *            collapses to UA-only — no harm, no extra strictness.
         *
         *   'ua'     Bind to User-Agent only. Tolerates IP changes
         *            (mobile↔WiFi, NAT, IPv6 rotation). Pick this if
         *            visitors complain about token expiry on mobile.
         *
         *   'none'   No client binding. Lowest friction, highest replay
         *            surface. Sensible only behind an upstream layer
         *            (WAF / IP-deny) that already gates per-client.
         */
        'bind_strategy' => 'ip_ua',
    ],

    // =================================================================
    // CAPTCHA (proof-of-work, dependency-free)
    // =================================================================

    /**
     * Hidden bot-defence: server issues a salt + difficulty, client JS
     * brute-forces a nonce so that sha256(salt . nonce) has at least
     * `difficulty` leading zero bits. Verified server-side at submit.
     *
     *   enabled     Master switch.
     *   difficulty  Leading zero bits the hash must have. Each +1 doubles
     *               the average attempt count for solvers.
     *                 12 → ~2k attempts (~50 ms)
     *                 16 → ~33k         (~0.5–1 s)   ← recommended default
     *                 18 → ~130k        (~2–4 s)
     *                 20 → ~520k        (~10–20 s)   too slow for legit users
     *   max_age     Seconds the issued challenge stays valid (anti-replay).
     */
    'captcha' => [
        'enabled'    => true,
        'difficulty' => 16,
        'max_age'    => 600,

        /**
         * Throttle for "captcha missing" submissions.
         *
         * An empty captcha nonce/token is treated as a usability fault
         * (browser without SubtleCrypto, e.g. on http://) — no strike,
         * the user sees a clear error message. Without throttling, a
         * bot can simply omit the captcha to skip the PoW step and
         * still hit the rate limit (5/window) before being slowed.
         *
         * This counter records every miss in a sliding window per IP.
         * Once `miss_threshold` misses accumulate within `miss_window`
         * seconds, the next miss escalates to a regular Blocklist
         * strike (which feeds the 3-strike soft block).
         *
         * Defaults are sized so a single legitimate user retrying on
         * a flaky browser (3 attempts) is fine, while a bot grinding
         * empty submits is contained.
         */
        'miss_threshold' => 3,
        'miss_window'    => 1800,  // 30 minutes
    ],

    // =================================================================
    // RATE LIMITING (per IP, sliding window)
    // =================================================================

    'rate_limit' => [
        'window_seconds' => 600,    // 10 minutes
        'max_per_window' => 5,      // 5 submissions per IP per window
    ],

    // =================================================================
    // SOFT BLOCKLIST (after repeated invalid submissions)
    // =================================================================

    'blocklist' => [
        'fail_threshold'  => 3,     // strikes before block
        'fail_window'     => 1800,  // 30 minutes for strikes to add up
        'block_duration'  => 1800,  // 30 minutes block on threshold hit
    ],
];
