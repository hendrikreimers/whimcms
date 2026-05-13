<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Http;

use H42\WhimCMS\Config;
use H42\WhimCMS\Http\Responder;
use H42\WhimCMS\Security\Form\Csrf;

/**
 * Static helpers for the security-relevant parts of the inbound HTTP
 * request: malformed-input rejection, client-IP resolution, and
 * derivation of the per-client binding key used by the CSRF token.
 *
 * Lives under `Security\Http\` (not the more obvious `Http\`) because
 * everything here is security-critical: a regression in any of these
 * three methods directly weakens the dispatcher's first-line defences
 * and the CSRF binding model. Keeping it under the `Security\` audit
 * anchor makes that explicit — when reviewing security posture, this
 * file is in the namespace tree you already grep.
 *
 * No instance state. The helpers read `$_SERVER` and the application
 * config singleton; the class is final and never instantiated.
 *
 * Security posture per method documented at the call site.
 */
final class RequestSecurity
{
    /**
     * Reject obviously-malicious header fields (control chars, null
     * bytes) before any path/header logic touches them. Sends a plain
     * `400 — Bad Request` response and exits the process; the caller
     * does not get control back.
     *
     * Targets the exact request fields the dispatcher then parses:
     * `REQUEST_URI` and `SCRIPT_NAME`. A NUL/CR/LF in either would
     * otherwise risk header-splitting, log-injection, or path-parser
     * confusion downstream.
     */
    public static function rejectUnsafeRequest(string $rawUri, string $scriptName): void
    {
        foreach ([$rawUri, $scriptName] as $candidate) {
            if (strpbrk($candidate, "\0\r\n") !== false) {
                Responder::plain(400, '400 — Bad Request');
                exit;
            }
        }
    }

    /**
     * Resolve the client IP address. Delegates to `ClientIp::resolve`,
     * which trusts `X-Forwarded-For` only when `REMOTE_ADDR` matches
     * a CIDR in `config/security.php → trusted_proxies`. With no
     * `trusted_proxies` configured (default), behaves identically
     * to the pre-trust-aware bare-REMOTE_ADDR resolution.
     *
     * Returns `0.0.0.0` for any non-IP value (extreme edge: PHP
     * running under SAPIs that don't populate `REMOTE_ADDR`).
     */
    public static function clientIp(): string
    {
        return ClientIp::resolve();
    }

    /**
     * Per-request binding key used to scope the CSRF/timing token to
     * the current client.
     *
     * The strategy (`'ua'` default, `'ip_ua'`, `'none'`) comes from
     * `csrf.bind_strategy` in `config/security.php`. See
     * `H42\WhimCMS\Security\Form\Csrf::deriveBindKey` for the full
     * trade-off discussion (loose = friendlier across NAT / mobile,
     * tight = harder to replay but blanks more legitimate clients).
     */
    public static function clientBindKey(): string
    {
        $ip = self::clientIp();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $strategy = (string)Config::get('csrf.bind_strategy', 'ua');
        return Csrf::deriveBindKey($ip, $ua, $strategy);
    }
}
