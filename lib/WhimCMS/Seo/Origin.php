<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;

/**
 * Resolve the absolute origin (`scheme://host[:port]`) used to build
 * canonical URLs, sitemap entries, and OG share links.
 *
 * Precedence:
 *   1. config.seo.canonical_origin — explicit, full-origin override.
 *      Highest priority; bypasses every Host inspection.
 *   2. config.seo.canonical_hosts — allowlist whose FIRST entry is the
 *      canonical host. The request `Host:` is ignored entirely — that's
 *      the whole point: an attacker sending `Host: evil.tld` cannot
 *      influence what URL the page emits.
 *
 * If neither is set, resolve() throws — the request `Host:` header is
 * never consulted. This used to fall back to a regex-filtered Host, but
 * that path was a Host-header-poisoning footgun a forgotten config wipe
 * would silently re-enable. Local-dev users opt in via a single line
 * `'canonical_hosts' => ['localhost']` and the failure mode goes away.
 */
final class Origin
{
    /**
     * Returns origin without a trailing slash, e.g. "https://example.com".
     *
     * @throws \RuntimeException when neither `seo.canonical_origin`
     *                           nor `seo.canonical_hosts` is configured.
     */
    public static function resolve(): string
    {
        $configured = (string)Config::get('seo.canonical_origin', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        /** @var array<int, string> $allowedHosts */
        $allowedHosts = (array)Config::get('seo.canonical_hosts', []);
        $allowedHosts = array_values(array_filter(array_map(
            static fn($v) => is_string($v) ? trim($v) : '',
            $allowedHosts
        ), static fn(string $h) => $h !== ''));

        if ($allowedHosts !== []) {
            // First entry is THE canonical host — emitted regardless of
            // what the request Host claims. Other entries are documentation
            // aliases handled at infra level (vhost rewrites etc.).
            return $scheme . '://' . $allowedHosts[0];
        }

        throw new \RuntimeException(
            'SEO origin unconfigured: set either seo.canonical_origin '
            . '(full URL) or seo.canonical_hosts (non-empty host list) '
            . 'in config/seo.php. The request Host header is never used '
            . 'as a fallback because that opens canonical/OG/sitemap '
            . 'URLs to Host-header poisoning.'
        );
    }
}
