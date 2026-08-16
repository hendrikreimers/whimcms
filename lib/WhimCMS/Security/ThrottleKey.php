<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security;

/**
 * Keyed bucket identifier for the per-client throttling stores.
 *
 * Single source of truth for the question "which counter does this
 * client belong to?". Used by `RateLimiter`, `Blocklist` and
 * `CaptchaMissTracker` — three stores that must agree on the answer,
 * because the captcha-miss tracker escalates into the blocklist: if
 * one counted per address while the other blocked per network, an
 * IPv6 client rotating addresses would never reach the miss threshold
 * and the escalation would be dead code.
 *
 * **Why a network, not an address.** With IPv4 one connection has one
 * public address, so hashing the address is a workable proxy for "one
 * subscriber, one counter". IPv6 breaks that: the provider delegates a
 * prefix to the connection and the *device* picks the lower 64 bits
 * itself — Windows, Android and iOS rotate them on a timer (privacy
 * extensions, RFC 7934 / RFC 6177). Bucketing the full address hands
 * a single connection 2^64 fresh counters, which makes the rate limit,
 * the soft blocklist and the captcha-miss throttle inoperative for
 * anyone on IPv6. Because `whimadmin` shares `RateLimiter`, that
 * includes the admin login.
 *
 * `/64` is the smallest unit whose INTERFACE half the client cannot
 * choose — precise about the residual: a subscriber delegated a /56
 * or /48 still picks the subnet ID and thus owns 256–65536 distinct
 * /64 buckets. That shrinks the evasion factor from 2^64 to at most
 * 2^16; going coarser to close the rest would bucket unrelated
 * customers of the same ISP together, which is the worse trade. The
 * industry lands in the same place: HAProxy ships `src,ipmask(32,64)`,
 * Cloudflare groups IPv6 rate limiting on /64 by default, DNSBLs list
 * IPv6 at /64 and coarser.
 *
 * Collateral is symmetric with what IPv4 already does: a guest WLAN
 * inside one /64 now shares a counter, exactly as everyone behind one
 * carrier-grade NAT address shares one today. This does not make IPv6
 * worse than IPv4 — it makes it equal.
 *
 * **Two traps this class handles that a naive "keep the upper 8 bytes"
 * does not.** Both would collapse unrelated clients into a single
 * bucket, which is worse than the gap being fixed: one bad actor could
 * then strike the shared bucket and lock everyone else out.
 *
 *   1. IPv4-mapped `::ffff:a.b.c.d` — a valid IPv6 literal whose upper
 *      8 bytes are all zero. It is folded down to its plain IPv4 form,
 *      so the MAPPED spelling and the bare IPv4 land in one bucket.
 *   2. Any other all-zero prefix (`::1`, the deprecated IPv4-compatible
 *      `::a.b.c.d` of RFC 4291 §2.5.5.1). `::/64` is not a network in
 *      any meaningful sense, so these keep their full (canonicalised)
 *      address. Deliberately NOT folded to IPv4: the compat form is
 *      deprecated and ambiguous, and it cannot reach this code anyway —
 *      every caller feeds addresses through `ClientIp::resolve()`,
 *      whose `filter_var` gate and proxy-appended XFF entries never
 *      produce it. It therefore forms its own (dead) bucket rather
 *      than merging with the bare IPv4 — narrower, never wider.
 *
 * Non-IP inputs pass through untouched, so a caller may add a second,
 * coarser bucket alongside the per-client one (e.g. a per-resource key
 * such as `'slug:foo'`). The `str_contains(':')` test alone would
 * misread such a key as an address; the `FILTER_VALIDATE_IP` half of
 * the condition is what keeps it safe.
 *
 * **What deliberately does NOT use this class:** the audit-trail
 * hashes in `Frontend\ContactController::hashIp` and
 * `WhimAdmin\Audit\Log::hashIp`. A log entry should identify a device,
 * a counter should identify a connection. See the comments there.
 *
 * Ported from the field-proven implementation in the photo-sharing
 * sibling application, with the two trap cases above added — that
 * deployment reads `REMOTE_ADDR` only, whereas this one can be
 * configured to trust `X-Forwarded-For`, so it must survive address
 * spellings chosen by an upstream proxy.
 */
final class ThrottleKey
{
    /**
     * Bucket key for `$client`: normalise to the network, then HMAC
     * with the application secret and truncate to 32 hex characters.
     *
     * The HMAC keeps plaintext addresses off disk (state files are
     * named after the result) and makes the identifier unguessable
     * without the secret. 32 hex characters = 128 bits, far past any
     * collision concern for this cardinality.
     */
    public static function derive(string $client, string $secret): string
    {
        return substr(hash_hmac('sha256', self::network($client), $secret), 0, 32);
    }

    /**
     * Reduce a client identifier to the unit that gets one counter.
     *
     * IPv6 → its `/64` prefix. IPv4, non-IP keys and every address
     * shape listed in the class docblock → returned unchanged. Any
     * parse failure falls back to the input verbatim: that can only
     * make a bucket *narrower* than intended, never wider, so a
     * malformed value can never merge unrelated clients.
     */
    private static function network(string $client): string
    {
        // A ':' is necessary but not sufficient — 'slug:foo' has one too.
        if (!str_contains($client, ':') || filter_var($client, FILTER_VALIDATE_IP) === false) {
            return $client;
        }

        $packed = @inet_pton($client);
        if ($packed === false || strlen($packed) !== 16) {
            return $client;
        }

        // Trap 1: ::ffff:a.b.c.d — same machine as the bare IPv4.
        if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xFF\xFF")) {
            $v4 = @inet_ntop(substr($packed, 12));
            return $v4 === false ? $client : $v4;
        }

        // Trap 2: an all-zero prefix is not a network. Keep the address,
        // canonicalised so two spellings cannot become two buckets.
        $prefix = substr($packed, 0, 8);
        if ($prefix === "\0\0\0\0\0\0\0\0") {
            $canonical = @inet_ntop($packed);
            return $canonical === false ? $client : $canonical;
        }

        $network = @inet_ntop($prefix . str_repeat("\0", 8));
        return $network === false ? $client : $network . '/64';
    }
}
