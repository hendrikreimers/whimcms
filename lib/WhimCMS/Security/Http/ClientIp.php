<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Http;

use H42\WhimCMS\Config;

/**
 * Resolve the real client IP behind optional reverse proxies.
 *
 * Why this exists separately from `RequestSecurity::clientIp`:
 *
 * The bare `REMOTE_ADDR` is the right answer on a direct-Apache
 * deployment. Behind a TLS-terminating reverse proxy (Cloudflare,
 * AWS ALB, nginx-proxy, fastly, …) it collapses to the proxy's IP
 * — every visitor looks like the same client, and the per-IP rate
 * limiter / CSRF binding / audit-IP-hash all degrade to a single
 * shared bucket. Effectively no brute-force throttle.
 *
 * This class lets the operator opt into trusting `X-Forwarded-For`,
 * but only when `REMOTE_ADDR` matches a configured CIDR range in
 * `config/security.php → trusted_proxies`. That allowlist is the
 * single piece of operator knowledge we accept on this path: "these
 * IPs are my real proxies, anything sourced from them carries an
 * XFF I should trust". Without the config, behaviour is identical
 * to the previous bare-REMOTE_ADDR resolution.
 *
 * **Never** trust `X-Forwarded-For` blindly. Spoofed XFF from a
 * direct client (`curl -H 'X-Forwarded-For: 1.2.3.4' …`) bypasses
 * everything if the receiving code reads it without a trusted-proxy
 * gate. The CIDR allowlist is THE gate.
 *
 * Algorithm when `REMOTE_ADDR` is in the trusted set:
 *
 *   1. Read `X-Forwarded-For`, split on commas.
 *   2. Walk the list from RIGHT (closest hop) to LEFT (original client).
 *   3. Skip every entry that is itself in `trusted_proxies` — those
 *      are intermediate hops we control.
 *   4. The first untrusted entry is the real client. Return it.
 *   5. If ALL entries are trusted hops (very unusual; means the chain
 *      didn't start with an untrusted client), fall back to the
 *      leftmost entry as a best-effort guess.
 *   6. If no resolvable XFF entry, fall back to REMOTE_ADDR.
 *
 * Supports IPv4 and IPv6 CIDRs via `inet_pton`. A single-IP entry
 * without `/N` is treated as `/32` (v4) or `/128` (v6).
 */
final class ClientIp
{
    /**
     * Resolve the client IP for the current request. Reads `$_SERVER`
     * directly so it works at the SAPI boundary before the Request
     * object is built. Returns `'0.0.0.0'` when nothing resolvable
     * is available (extreme edge: PHP under a SAPI that doesn't
     * populate `REMOTE_ADDR`).
     */
    public static function resolve(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $trusted = self::trustedProxies();
        if ($trusted === [] || !self::ipInRanges($remote, $trusted)) {
            return $remote;
        }

        // REMOTE_ADDR is a trusted proxy → parse X-Forwarded-For
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') {
            return $remote;
        }

        $hops = array_values(array_filter(
            array_map('trim', explode(',', $xff)),
            static fn(string $s): bool => $s !== '',
        ));

        // Walk right-to-left, skipping known proxies. First untrusted
        // IP is the client.
        foreach (array_reverse($hops) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) continue;
            if (!self::ipInRanges($hop, $trusted)) return $hop;
        }

        // All hops were trusted. Best-effort: leftmost resolvable IP.
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) !== false) return $hop;
        }
        return $remote;
    }

    /**
     * Test if `$ip` falls within any of the given CIDR ranges.
     *
     * @param list<mixed> $ranges
     */
    public static function ipInRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (!is_string($range) || $range === '') continue;
            if (self::ipInCidr($ip, $range)) return true;
        }
        return false;
    }

    /**
     * Test if `$ip` matches `$cidr`. Accepts both `a.b.c.d/N` and
     * `a.b.c.d` forms (the latter implicitly /32 for IPv4 or /128
     * for IPv6). Returns false on any parse error — a malformed
     * range can never match.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $net   = $parts[0];
        $bits  = isset($parts[1]) ? (int)$parts[1] : null;

        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false) return false;
        // v4-vs-v6 mismatch: lengths differ (4 vs 16 bytes).
        if (strlen($ipBin) !== strlen($netBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        if ($bits === null) {
            $bits = $maxBits;
        }
        if ($bits < 0 || $bits > $maxBits) return false;

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0
            && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainder > 0) {
            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pull the trusted-proxies CIDR list from config. Tolerates the
     * config being absent (empty list = pass-through) or
     * malformed (filter to strings; non-strings dropped silently).
     *
     * @return list<string>
     */
    private static function trustedProxies(): array
    {
        if (!Config::isLoaded()) return [];
        $raw = Config::get('trusted_proxies', []);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v) && $v !== '') $out[] = $v;
        }
        return $out;
    }
}
