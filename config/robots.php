<?php
declare(strict_types=1);

/**
 * Drives the dynamic /robots.txt response (lib/WhimCMS/Seo/Robots.php).
 *
 * Only consulted when `seo.indexable` is true. When indexable is false
 * the endpoint always emits a hard, unconditional "Disallow: /" for all
 * user-agents — categories, override, and extend below are ignored, so a
 * pre-launch / staging site can never be re-opened from here or from
 * content/. config/ has priority, full stop.
 *
 * When indexable is true the response is built as:
 *   User-agent: *
 *   Allow: /
 *   <each active category block: named UAs + Allow:/ or Disallow:/>
 *   <extend file body, if enabled>
 *   Sitemap: <origin>/sitemap.xml
 * A configured override file replaces that entire generated body.
 *
 * Bot lists are data, curated here (stand: 2026) so they can be updated
 * without touching the audited engine in lib/.
 */

return ['robots' => [

    /**
     * Editor-managed files under content/ that can shape robots.txt.
     * Each is a BARE filename directly in content/ (no path component — a
     * value containing '/', '\', a leading dot or a null byte is rejected
     * outright), or null to disable that capability entirely.
     *
     * Default null on both: on a fresh install the content editor can
     * NOT affect robots.txt. The site dev opts in per deployment by
     * setting a filename — a deliberate, visible switch.
     *
     *   robots_override  Its verbatim body REPLACES the whole generated
     *                    robots.txt (including the Sitemap line). Full
     *                    manual control for the editor. Active only when
     *                    the named file exists AND is non-empty; an empty
     *                    / whitespace-only file falls through to the
     *                    generated body (no accidental blanking).
     *   robots_extend    Its verbatim body is appended AFTER the category
     *                    blocks and BEFORE the Sitemap line. Additive;
     *                    skipped when empty / whitespace-only.
     *
     * Both are gated on `seo.indexable = true` and read only from the
     * validated content directory; the body is size-capped (16 KB) and
     * stripped of control characters (CR/LF normalised to LF).
     */
    'robots_override' => null,
    'robots_extend'   => null,

    /**
     * Category blocks. Each entry:
     *   mode          When the block appears and how llms affects its
     *                 direction (see modes below).
     *   rule          Direction: 'disallow' (default) or 'allow'. Emitted
     *                 as "<Disallow|Allow>: /" after the User-agent lines.
     *   label         Rendered as the "# <label>" comment above the block.
     *   crawler_list  User-agent tokens; each emitted as its own
     *                 "User-agent: <token>" line.
     *
     * Modes (all require indexable = true; nothing is emitted otherwise):
     *   seo_enabled    emitted; uses `rule` as-is
     *   llms_enabled   emitted; uses `rule` when seo.llms.enabled == true,
     *                  else forced to Disallow — so an llms-gated allowance
     *                  (e.g. ai_search) never leaks without an llms.txt
     *   disabled       never emitted (temporary off switch)
     *
     * An unknown rule falls back to 'disallow', and an unknown mode is
     * forced to 'Disallow: /' (fail-safe — a `mode` typo can never silently
     * un-block a category). Only the explicit 'disabled' opts a block out.
     * NOTE: an explicit 'allow' only matters for a block that would
     * otherwise be disallowed — the top Allow already permits un-named bots.
     *
     * CAVEAT — robots.txt is advisory. Several listed agents are known to
     * IGNORE it and can only be stopped at the WAF / IP layer:
     *   Bytespider (ByteDance), xAI Grok (no token at all — UA/IP spoofing),
     *   Perplexity-User, ChatGPT-User (user-initiated), archive.org_bot
     *   (Internet Archive, since 2017). Listing them here still documents
     *   intent and stops the compliant subset; it is not enforcement.
     */
    'categories' => [

        // AI training crawlers.
        'ai_training' => [
            'mode'         => 'seo_enabled',
            'rule'         => 'disallow',
            'label'        => 'AI training crawlers',
            'crawler_list' => [
                // Active vendor tokens.
                'GPTBot', 'ClaudeBot', 'CCBot', 'Google-Extended',
                'Applebot-Extended', 'Bytespider', 'Meta-ExternalAgent',
                'Amazonbot', 'PetalBot', 'Diffbot', 'ImagesiftBot',
                'Timpibot', 'Webzio-Extended', 'img2dataset',
                'VelenPublicWebCrawler',
                'cohere-training-data-crawler', 'PanguBot', 'AI2Bot',
                'AI2Bot-Dolma',
                // Legacy / superseded tokens — kept for old-crawler
                // coverage, no longer the vendor's live token:
                //   anthropic-ai, Claude-Web -> ClaudeBot
                //   cohere-ai                -> cohere-training-data-crawler
                //   FacebookBot              -> Meta-ExternalAgent
                //   Omgili, Omgilibot        -> Webzio-Extended
                'anthropic-ai', 'Claude-Web', 'cohere-ai', 'FacebookBot',
                'Omgili', 'Omgilibot',
            ],
        ],

        // Web archives. NOTE: the Internet Archive (archive.org_bot) has
        // ignored robots.txt since 2017 — blocking here is advisory only
        // (WAF/IP or email removal for real control). 'ia_archiver' is the
        // defunct Alexa crawler (NOT the Wayback Machine); the
        // 'ia_archiver-web.archive.org' token is a misattributed myth.
        // Both are kept only as harmless historical coverage.
        'archives' => [
            'mode'         => 'seo_enabled',
            'rule'         => 'disallow',
            'label'        => 'Web archives',
            'crawler_list' => [
                'archive.org_bot', 'ia_archiver', 'ia_archiver-web.archive.org',
            ],
        ],

        // SEO / backlink crawlers.
        'seo' => [
            'mode'         => 'seo_enabled',
            'rule'         => 'disallow',
            'label'        => 'SEO / backlink crawlers',
            'crawler_list' => [
                'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'rogerbot',
                'BLEXBot', 'DataForSeoBot', 'SEOkicks', 'SISTRIX Crawler',
                'MegaIndex', 'serpstatbot',
            ],
        ],

        // AI search / retrieval. `llms_enabled` + `allow`: while llms.txt is
        // published the block emits "Allow: /" (you WANT AI retrieval); with
        // no llms.txt the mode forces "Disallow: /" (full AI exclusion). One
        // category covers both states.
        'ai_search' => [
            'mode'         => 'llms_enabled',
            'rule'         => 'allow',
            'label'        => 'AI search / retrieval',
            'crawler_list' => [
                'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot',
                'Claude-User', 'PerplexityBot', 'Perplexity-User',
                'DuckAssistBot', 'MistralAI-User', 'MistralAI-Index',
                'Meta-ExternalFetcher', 'YouBot',
            ],
        ],
    ],
]];
