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
    ],
];
