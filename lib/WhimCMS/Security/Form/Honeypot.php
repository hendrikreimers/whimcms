<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Form;

/**
 * Honeypot-field-name derivation.
 *
 * Bots maintain dictionaries of common honeypot field names ("website",
 * "url", "phone", "email_address"). To avoid that signal, the input's
 * `name` attribute is derived per-installation from the application
 * secret — random-looking but stable across renders and submits, and
 * automatic (no manual config rotation needed).
 *
 * Properties:
 *   - 12 hex characters → 48 bits of namespace; collisions across
 *     installations are irrelevant since each site sees only its own.
 *   - Underscore prefix keeps the name a valid HTML/PHP identifier
 *     and avoids accidental collision with real form fields, which
 *     never start with `_` in this project.
 *   - Pure [a-z0-9_] — no quotes, no escape-relevant characters, so
 *     the value is safe to drop into HTML attributes / PHP array keys
 *     / regex without further escaping.
 *
 * Stable as long as the application secret is stable. Rotating the
 * secret rotates the honeypot name automatically — desirable: any bot
 * that learned the previous name has to re-discover after the rotation.
 *
 * The optional `'honeypot_field'` config setting overrides this default
 * with a literal string. Leave it as null in normal deployments so the
 * derived name is used.
 */
final class Honeypot
{
    /**
     * Derive the field name from the secret. Stable for a given secret.
     */
    public static function deriveFieldName(string $secret): string
    {
        // Versioned namespace ('v1') so the derivation can be migrated
        // later (e.g. wider field, different hash) without colliding
        // with the original derivation when both are read briefly during
        // a transition.
        return '_' . substr(hash_hmac('sha256', 'honeypot:v1', $secret), 0, 12);
    }

    /**
     * Resolve the effective honeypot field for the given config + secret.
     * Honours an explicit string override if config provides one;
     * otherwise returns the derived name.
     *
     * @param array<string, mixed> $contactConfig  config('contact') section
     */
    public static function resolveFieldName(array $contactConfig, string $secret): string
    {
        $override = $contactConfig['honeypot_field'] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }
        return self::deriveFieldName($secret);
    }
}
