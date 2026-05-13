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

    // =================================================================
    // TRUSTED PROXIES (X-Forwarded-For handling)
    // =================================================================

    /**
     * Behind a reverse proxy (Cloudflare, AWS ALB, nginx-proxy,
     * fastly, …) the bare `REMOTE_ADDR` is the proxy's IP, not the
     * client's. With this list empty (the default), WhimCMS uses
     * `REMOTE_ADDR` as-is and ignores `X-Forwarded-For` entirely —
     * the safe-by-default posture for direct-Apache deployments.
     *
     * If you deploy behind a proxy you control, list its source IP
     * ranges here. The resolver will then read `X-Forwarded-For`
     * ONLY when `REMOTE_ADDR` is in the list, and pick the
     * rightmost untrusted IP from the chain as the real client.
     *
     * **Crucially**: list ONLY proxies whose XFF header you trust.
     * Listing 0.0.0.0/0 effectively disables the gate and lets any
     * direct attacker spoof their source IP via `curl -H
     * 'X-Forwarded-For: ...'`. Stick to CIDRs of YOUR upstream
     * infrastructure.
     *
     * Format: list of CIDR strings (IPv4 or IPv6).
     *
     * Examples:
     *   - Cloudflare: list their published proxy ranges, e.g.
     *     `'173.245.48.0/20', '103.21.244.0/22', …`
     *     (see https://www.cloudflare.com/ips/ — update periodically)
     *   - Same-host nginx in front of Apache: `'127.0.0.1/32', '::1/128'`
     *   - Docker bridge: `'172.17.0.0/16'`
     *
     * If you run behind a CDN, ALSO configure Apache `mod_remoteip`
     * with `RemoteIPHeader X-Forwarded-For` and `RemoteIPInternalProxy
     * <cidr>` for each entry above. mod_remoteip rewrites REMOTE_ADDR
     * before PHP sees it, which feeds this resolver the proxy IP
     * (matching the allowlist) and the chain in XFF.
     *
     * Operator burden: keep this list current with your upstream's
     * actual IP ranges. A stale entry just means the gate stops
     * recognising that proxy and visitors via it get bucketed onto
     * the proxy IP again — degrading throttling but not breaking
     * anything.
     */
    'trusted_proxies' => [],
];
