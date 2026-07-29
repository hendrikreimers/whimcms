<?php
declare(strict_types=1);

/**
 * Search-engine + social-meta configuration. Drives the per-page
 * canonical/alternate/OG/Twitter/JSON-LD tags emitted by the layout
 * plus the dynamic /robots.txt and /sitemap.xml endpoints.
 *
 *   indexable          When false, robots.txt disallows everything
 *                      and every page emits <meta name="robots"
 *                      content="noindex,nofollow,…">. Flip to true
 *                      on launch — pair with removing the
 *                      X-Robots-Tag noindex header in .htaccess.
 *
 *   canonical_origin   Absolute origin used to build canonical /
 *                      sitemap URLs (e.g. "https://example.com").
 *                      Leave empty to auto-detect from the request
 *                      host — fine for dev, set explicitly for prod
 *                      so an injected Host header can't poison links.
 *
 *   og_image           Default OG/Twitter share image, base-relative
 *                      path under /assets. Used for every page.
 *
 *   organization /     Backing data for the JSON-LD structured-data
 *   person             block. Empty/missing fields are dropped from
 *                      the output. Leave `person` empty for non-
 *                      personal sites.
 *
 *   llms               When `llms.enabled` AND `indexable` are both
 *                      true, /llms.txt (llmstxt.org) serves a plain-
 *                      text page index for LLM crawlers. On a non-
 *                      indexable site the endpoint 404s, so a staging
 *                      deploy never advertises its page list.
 */

return [
    'seo' => [
        /**
         * SECURITY: Allowlist of accepted Host header values used to build
         * canonical / Open-Graph / sitemap URLs. The first entry is the
         * primary canonical host and is ALWAYS what the site emits,
         * regardless of the inbound `Host:` header. Any other entries are
         * documentation aliases the host accepts at infrastructure level
         * (e.g. www / non-www variants reaching the same vhost) — they
         * are NOT used as canonical URLs.
         *
         * Why this matters: an attacker can send `Host: evil.tld` against
         * any server and, with auto-detect, the response would emit URLs
         * pointing at evil.tld. A CDN, search bot, or social-card scraper
         * caching that response would persist the poisoned canonical /
         * sitemap, harming SEO and trust.
         *
         * Leave EMPTY only in dev. Leaving it empty in production means
         * the regex-filtered `Host:` header is used, which is NOT
         * poisoning-safe. The `canonical_origin` setting overrides this
         * entirely when set.
         */
        'canonical_hosts'  => [],

        'indexable'        => false,
        'canonical_origin' => '',
        'site_name'        => 'WhimCMS',
        'og_image'         => '/assets/images/placeholder/core/hero.jpg',
        'twitter_handle'   => '',
        'sitemap_exclude'  => [],

        /**
         * llms.txt endpoint (llmstxt.org). Served at /llms.txt and
         * /<lang>/llms.txt ONLY when `enabled` is true AND the global
         * `indexable` flag above is true — a non-indexable site must
         * not advertise its pages. Page selection mirrors the sitemap
         * (skips hidden/disabled pages and `sitemap_exclude` slugs); a
         * page can additionally opt out via `llms: exclude` in its
         * front-matter, or steer placement with `llms: feature` /
         * `llms: optional`.
         *
         * NOTE: config/robots.php's `ai_search` block is coupled to this
         * flag. While `enabled` is false it emits `Disallow: /` (full AI
         * exclusion); once `enabled` is true it flips to `Allow: /` —
         * publishing an llms.txt signals you WANT AI retrieval. The block
         * is always present when indexable; only its direction changes.
         * See the `robots` section (config/robots.php) for the coupling.
         */
        'llms' => [
            'enabled' => false,
        ],

        'organization' => [
            'name'   => 'WhimCMS',
            'logo'   => '/theme/assets/whimcms-mark.svg',
            'sameAs' => [],
        ],
        'person' => [
            'name'     => '',
            'jobTitle' => '',
            'image'    => '',
        ],
    ],
];
