<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Form;

/**
 * Stateless CSRF / form-timing token, optionally bound to a client
 * fingerprint (IP + User-Agent) so a token issued to one client cannot
 * be replayed by another, and scoped to a specific form so a token
 * issued for one form cannot be replayed against another.
 *
 * Format:
 *   token = base64( <issued-unix-ts> "." hex(hmac_sha256(<ts>|<bindKey>|<formId>, secret)) )
 *
 * The timestamp doubles as the form-render time, so validation enforces:
 *   - signature integrity (only the server could have issued it)
 *   - age window  [min_age_seconds, max_age_seconds]
 *     (anti-bot speed limit + anti-replay expiry)
 *   - client binding: the same `bindKey` must be presented at validate
 *     time. With bindKey = sha256(ip || UA), a token grabbed by a bot
 *     in one network is useless from another network or another browser.
 *   - form scope: the same `formId` (server-chosen string identifying
 *     the form, e.g. 'contact' or 'booking') must be presented at
 *     validate time. Prevents token confusion between distinct POST
 *     endpoints — a token issued for the contact form cannot be POSTed
 *     to a hypothetical booking form, and vice versa.
 *
 * Pass `bindKey = ''` to issue/validate without client binding (only
 * useful in dev / unit tests; production code should always bind).
 * Pass `formId = ''` for global / form-agnostic tokens (avoid in
 * production — always pick a stable, distinct string per form).
 *
 * No DB, no session, no cookie needed.
 *
 * Thread-safe: hash_equals is constant-time so we don't leak signature
 * bytes via timing comparison.
 */
final class Csrf
{
    /**
     * Derive a stable binding key from a client's IP and User-Agent.
     * Hashed (not stored verbatim) so we don't have to track length /
     * encoding of UA strings, and so the binding material is uniform
     * across the issue / validate call sites.
     *
     * Strategy values (production default is `'ip_ua'` via config —
     * see `config/security.php`. The function-level parameter default
     * stays `'ua'` for direct/test callers; it never wins at runtime
     * because the Kernel always passes the config value explicitly):
     *
     *   'ip_ua'  IP and UA. Strictest. Catches token replay across
     *            networks AND across browsers. Trade-off: mobile users
     *            on transitioning networks (mobile↔WiFi roaming, NAT
     *            hop, IPv6 rotation) may see "session expired" mid-
     *            submit. Behind a CDN where REMOTE_ADDR is the edge,
     *            this collapses to UA-only — no harm.
     *
     *   'ua'     UA only. Tolerates IP changes — pick this if mobile
     *            visitors complain. A token issued to one browser is
     *            still unusable from a different browser.
     *
     *   'none'   No client binding. Token validates only on signature
     *            + age. Lowest friction; highest replay surface.
     *            Sensible only when an upstream layer (IP-blocklist,
     *            WAF) already gates per-client.
     *
     * Unknown values fall back to UA-only — misconfig should not
     * silently disable binding.
     */
    public static function deriveBindKey(string $ip, string $userAgent, string $strategy = 'ua'): string
    {
        return match ($strategy) {
            'ip_ua' => hash('sha256', $ip . "\0" . $userAgent),
            'none'  => '',
            default => hash('sha256', $userAgent),
        };
    }

    /**
     * Issue a fresh token tied to the current time, the client binding
     * key, and a form-scope identifier. Pass the same `$formId` at
     * validate time to recognise the token; any other formId rejects.
     */
    public static function issue(string $secret, string $bindKey = '', string $formId = '', ?int $now = null): string
    {
        $now = $now ?? time();
        $payload = (string)$now;
        $sig = hash_hmac('sha256', $payload . '|' . $bindKey . '|' . $formId, $secret);
        return rtrim(strtr(base64_encode($payload . '.' . $sig), '+/', '-_'), '=');
    }

    /**
     * Validate a token against the binding key AND form scope it was
     * issued under. Returns true only if signature matches, age is
     * inside the configured window, the bind key still matches, and
     * the formId matches. Any malformed input returns false.
     */
    public static function validate(
        string $token,
        string $secret,
        string $bindKey,
        string $formId,
        int $minAgeSeconds,
        int $maxAgeSeconds,
        ?int $now = null
    ): bool {
        if ($token === '' || strlen($token) > 200) {
            return false;
        }
        $padded = $token . str_repeat('=', (4 - strlen($token) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('.', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$payload, $sig] = $parts;
        if ($payload === '' || $sig === '' || !ctype_digit($payload)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload . '|' . $bindKey . '|' . $formId, $secret);
        if (!hash_equals($expected, $sig)) {
            return false;
        }

        $now    = $now ?? time();
        $issued = (int)$payload;
        $age    = $now - $issued;
        return $age >= $minAgeSeconds && $age <= $maxAgeSeconds;
    }
}
