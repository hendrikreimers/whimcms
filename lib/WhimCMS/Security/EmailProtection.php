<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security;

/**
 * Email-address obfuscation helper.
 *
 * Splits a plain "user@domain" address into a struct the render layer
 * can drop into the page either:
 *   - as a normal mailto link (when protection is disabled), or
 *   - as a non-clickable obfuscated span (`js-email`) that
 *     `js/email.js` rehydrates into a real anchor at load time.
 *
 * Format string: any literal text plus `%user%` and `%domain%`
 * placeholders. Common patterns:
 *   "%user% [at] %domain%"
 *   "%user% (at) %domain%"
 *   "%user%@%domain%"   (no obfuscation; just the plain address)
 */
final class EmailProtection
{
    /**
     * @return array{
     *   raw: string,
     *   user: string,
     *   domain: string,
     *   display: string,
     *   protected: bool
     * }
     */
    public static function buildStruct(string $email, string $format, bool $enabled): array
    {
        $email = trim($email);
        $at = strpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            // Malformed address — return a passive struct so callers
            // don't need to special-case missing fields.
            return [
                'raw'       => $email,
                'user'      => $email,
                'domain'    => '',
                'display'   => $email,
                'protected' => false,
            ];
        }
        $user = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $display = $enabled
            ? strtr($format, ['%user%' => $user, '%domain%' => $domain])
            : $email;
        return [
            'raw'       => $email,
            'user'      => $user,
            'domain'    => $domain,
            'display'   => $display,
            'protected' => $enabled,
        ];
    }

    /**
     * Build the EMAIL render-context map from the configured paths.
     *
     * @param array<string, mixed>          $dict   The active language dictionary
     * @param array<string, string>         $paths  Map of context key → dot-path in $dict
     * @return array<string, array<string, mixed>>
     */
    public static function buildContext(array $dict, array $paths, string $format, bool $enabled): array
    {
        $out = [];
        foreach ($paths as $key => $path) {
            $value = self::lookup($dict, (string)$path);
            if (!is_string($value) || $value === '') {
                continue;
            }
            $out[(string)$key] = self::buildStruct($value, $format, $enabled);
        }
        return $out;
    }

    /**
     * Resolve a dot-path against an array (mirrors Expression::lookup
     * but kept local so this class doesn't depend on the template
     * engine).
     *
     * @param array<string, mixed> $node
     */
    private static function lookup(array $node, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        $cur = $node;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
                continue;
            }
            return null;
        }
        return $cur;
    }
}
