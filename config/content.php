<?php
declare(strict_types=1);

/**
 * Block-based content engine that renders content/<lang>/<slug>.md
 * into the page-blocks pipeline. See _docs/CONTENT.md.
 *
 *   max_bytes             Hard ceiling per .md file. Defence against
 *                         pathological inputs; real pages are <30 KB.
 *   allowed_layouts       Whitelist of layout names a page can opt
 *                         into via front-matter `layout: <name>`.
 *                         Unknown values fail loud at parse time.
 *                         `default` maps to templates/layout.html;
 *                         `<other>` maps to templates/layout-<other>.html.
 *   cache_sweep_interval  Cache-cleanup interval (seconds). Sweeper
 *                         walks var/cache/content/, drops cache files
 *                         whose source .md no longer exists. Sentinel-
 *                         gated and lock-protected. 0 still floors
 *                         at 60 s.
 */

return [
    'content' => [
        'max_bytes'            => 262144,            // 256 KiB
        // `default` → templates/layout.html (the WhimCMS core layout).
        // Each demo theme adds a layout name here that maps to
        // templates/layout-<name>.html + styles/theme-<name>.css.
        'allowed_layouts'      => ['default', 'business', 'personal', 'trainer', 'dev'],
        'cache_sweep_interval' => 86400,             // 24 h

        /*
         * Per-block attribute parser limits.
         *
         * Hard caps applied while parsing block attributes and
         * page front-matter. Raise these if a real-world title or
         * description legitimately needs more bytes than the
         * default. Both values are floored at sensible minimums in
         * AttributeParser::setLimits so a typo here can't degrade
         * the parser into rejecting valid input.
         *
         *   max_lines       — maximum total lines per attribute
         *                     block. Default 500. Minimum 100.
         *   max_value_len   — maximum bytes per single scalar value.
         *                     Default 4096. Minimum 256.
         */
        'attribute_parser' => [
            'max_lines'     => 500,
            'max_value_len' => 4096,
        ],
    ],
];
