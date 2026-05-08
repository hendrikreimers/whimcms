<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Form\Captcha;

/**
 * Stateless proof-of-work captcha.
 *
 * Issuance:
 *   token = base64url("<ts>:<difficulty>:<salt>:<hmac>")
 * where hmac = HMAC-SHA-256("<ts>:<difficulty>:<salt>", secret).
 *
 * Submission:
 *   client computes a `nonce` such that the first `<difficulty>` bits
 *   of sha256(salt . nonce) are zero. The token (unchanged) and the
 *   nonce are sent back with the form.
 *
 * Validation:
 *   - HMAC must match → token is server-issued and untampered.
 *   - Age must be ≤ max_age → guards replay.
 *   - sha256(salt . nonce) must have ≥ difficulty leading zero bits
 *     → caller actually paid the CPU cost.
 *
 * No DB, no session — same `secret` as Csrf signs both.
 */
final class Captcha
{
    /** @return array{token: string, salt: string, difficulty: int} */
    public static function issue(string $secret, int $difficulty, ?int $now = null): array
    {
        $now = $now ?? time();
        // 16 bytes = 128 bits. Sufficient to make per-issuance salt collisions
        // statistically irrelevant (>2^64 issuances before any chance of
        // collision). The HMAC over (ts:diff:salt) makes salt collisions
        // benign anyway, but 128 bits is the conventional minimum.
        $salt = bin2hex(random_bytes(16));
        $payload = $now . ':' . $difficulty . ':' . $salt;
        $hmac = hash_hmac('sha256', $payload, $secret);
        $token = rtrim(strtr(base64_encode($payload . ':' . $hmac), '+/', '-_'), '=');
        return ['token' => $token, 'salt' => $salt, 'difficulty' => $difficulty];
    }

    /**
     * Verify token + nonce. Returns true only if every check passes.
     * Any malformed input quietly returns false (no exception thrown
     * so the caller can simply count this as a strike).
     */
    public static function validate(
        string $token,
        string $nonce,
        string $secret,
        int $maxAge,
        ?int $now = null
    ): bool {
        if ($token === '' || strlen($token) > 200) {
            return false;
        }
        // Nonces are short ASCII numbers in our solver; accept up to 64 alphanumerics.
        if ($nonce === '' || strlen($nonce) > 64 || preg_match('/^[A-Za-z0-9]+$/', $nonce) !== 1) {
            return false;
        }

        $padded = $token . str_repeat('=', (4 - strlen($token) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 4) {
            return false;
        }
        [$tsStr, $diffStr, $salt, $hmac] = $parts;
        if (!ctype_digit($tsStr) || !ctype_digit($diffStr) || $salt === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $tsStr . ':' . $diffStr . ':' . $salt, $secret);
        if (!hash_equals($expected, $hmac)) {
            return false;
        }

        $now = $now ?? time();
        $age = $now - (int)$tsStr;
        if ($age < 0 || $age > $maxAge) {
            return false;
        }

        return self::nonceSatisfies($salt, $nonce, (int)$diffStr);
    }

    /**
     * True iff sha256(salt . nonce) has ≥ $difficulty leading zero bits.
     *
     * The cap is 32 bits — well above the realistic configured ceiling
     * (around 20, where legit users would already be waiting tens of
     * seconds) and well below the theoretical 256-bit hash output where
     * any value approaching that would have been rejected for max_age
     * expiry long before it solved. Keeping the cap honest prevents the
     * range-check from looking like it operates on values it never sees.
     */
    private static function nonceSatisfies(string $salt, string $nonce, int $difficulty): bool
    {
        if ($difficulty < 0 || $difficulty > 32) {
            return false;
        }
        $hash = hash('sha256', $salt . $nonce, true); // raw bytes
        return self::leadingZeroBits($hash) >= $difficulty;
    }

    private static function leadingZeroBits(string $bytes): int
    {
        $count = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);
            if ($b === 0) {
                $count += 8;
                continue;
            }
            for ($j = 7; $j >= 0; $j--) {
                if ((($b >> $j) & 1) !== 0) {
                    return $count + (7 - $j);
                }
            }
        }
        return $count;
    }
}
