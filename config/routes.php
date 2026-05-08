<?php
declare(strict_types=1);

/**
 * URL → page mapping per language.
 *
 * Outer key  = language code
 * Inner key  = URL segment in that language ("" = home)
 * Inner val  = canonical page slug
 *
 * Adding a page: add the canonical slug under every language with the
 * localised URL segment, and create content/<lang>/<slug>.md (or
 * templates/pages/<slug>.html for the legacy per-page template path).
 *
 * Multi-segment URLs ("demos/business") are stored as a single literal
 * key — the router does an exact-string lookup, no path-traversal logic.
 *
 * Languages a slug isn't published in just don't list it. Router::
 * buildLangSwitch filters those out automatically so the language
 * switcher never renders a dead link.
 */

return [
    'routes' => [
        'en' => [
            ''                  => 'home',
            'imprint'           => 'imprint',
            'privacy'           => 'privacy',

            // ----- Demo themes (bundled showcase) -----
            // Each entry below is a single-page demo of WhimCMS rendered
            // through a different layout + stylesheet bundle. They share
            // the same block pool — only the layout, CSS, and content
            // differ. Strip them when deploying as a real site.
            'demos/business'    => 'demo-business',
            'demos/personal'    => 'demo-personal',
            'demos/trainer'     => 'demo-trainer',
            'demos/dev'         => 'demo-dev',
        ],
        'de' => [
            ''                  => 'home',
            'impressum'         => 'imprint',
            'datenschutz'       => 'privacy',
            // Demo themes are published in English only. The language
            // switcher hides German automatically on /demos/* pages.
        ],
    ],
];
