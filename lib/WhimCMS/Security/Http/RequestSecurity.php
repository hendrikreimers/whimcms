<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Http;

use H42\WhimCMS\Config;
use H42\WhimCMS\Http\Responder;
use H42\WhimCMS\Log;
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
     *
     * Second rejection: a scheme-relative request target. This is the
     * INBOUND counterpart to `Responder::isSafeRedirectTarget()` — the
     * same two characters, the other direction. Rationale in the body.
     */
    public static function rejectUnsafeRequest(string $rawUri, string $scriptName): void
    {
        foreach ([$rawUri, $scriptName] as $candidate) {
            if (strpbrk($candidate, "\0\r\n") !== false) {
                Responder::plain(400, '400 — Bad Request');
                exit;
            }
        }

        // A request target starting with `//` (or `/\`) makes Apache and
        // PHP resolve DIFFERENT paths for one request.
        //
        // `Router::stripBase` derives the path with
        // `parse_url($uri, PHP_URL_PATH)` (Router.php:55, there to strip
        // the query string and fragment). Per RFC 3986 §4.2 a leading
        // `//` introduces an AUTHORITY, so parse_url discards the first
        // segment: `//anything/de/contact` becomes `/de/contact`. Apache
        // meanwhile still sees `//anything/…`, so every Apache rule
        // anchored on `^/<path>` stops matching — while the front
        // controller happily serves the page.
        //
        // In this deployment that defeated the HTTP Basic-Auth gate in
        // front of the internal catalogue, which is the project's only
        // authorisation decision bound to a URI pattern rather than to a
        // directory. Confirmed on the running site, 2026-08-13; see
        // `_docs/audits/2026-08-13_core-review-five-perspectives.md`, R1.
        //
        // Deliberately NARROW — only a LEADING `//` or `/\`. Not "must
        // begin with exactly one slash": that would also turn
        // `OPTIONS *` and an empty REQUEST_URI into 400s, both of which
        // are refused anyway today, for no gain. A double slash INSIDE
        // the path is untouched and needs no guard (`/de//contact`
        // resolves to nothing and 404s on its own).
        //
        // No legitimate client sends this for our host: `//host/path` is
        // a protocol-relative reference and addresses a DIFFERENT host,
        // so a browser resolving it never reaches us at all.
        if (isset($rawUri[1]) && $rawUri[0] === '/' && ($rawUri[1] === '/' || $rawUri[1] === '\\')) {
            Log::warn('RequestSecurity: rejected scheme-relative request target', [
                'uri_prefix' => substr($rawUri, 0, 64),
            ]);
            Responder::plain(400, '400 — Bad Request');
            exit;
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
