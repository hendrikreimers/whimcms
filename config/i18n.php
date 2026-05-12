<?php
declare(strict_types=1);

/**
 * Internationalisation: which languages the site is published in,
 * which is the fallback, and whether to sniff Accept-Language at the
 * unprefixed root URL.
 */

return [
    /**
     * Languages the site is published in. Must match the keys of `routes`
     * and the file names under /i18n/. Order is the display order in the
     * language switcher.
     *
     * The bundled showcase ships in English and German. Drop one to run
     * single-language (the URL stops emitting a language prefix).
     */
    'supported_langs' => ['en', 'de'],

    /**
     * Fallback language. Used as the redirect target for "/" when
     * detect_lang is false, and for any branch where Accept-Language
     * yields no supported match.
     */
    'default_lang' => 'en',

    /**
     * If true, "/" without a language prefix will sniff the
     * Accept-Language header and 302 to the closest supported match
     * (falling back to default_lang). If false, "/" always 302s to
     * default_lang.
     */
    'detect_lang' => true,

    /**
     * Editor-managed i18n overlay.
     *
     * If a file `content/_i18n_overlay.<lang>.json` exists, its
     * top-level sections — filtered against the allowlist below —
     * are deep-merged on top of the theme's `i18n/<lang>.json`
     * before any template renders. Lets the editor own nav
     * structure, page meta overrides, and footer copy without
     * touching theme files; survives theme updates because the
     * overlay lives in `content/`, which is editor-domain.
     *
     * `allowed_sections` is the security boundary. Any top-level
     * key the overlay writes that ISN'T listed here is silently
     * dropped by the loader. Keep the list as tight as the editor
     * surface requires — broadening it means handing more of the
     * dictionary to whoever can edit the file. In particular,
     * `errors`, `a11y`, `contactMail`, `home.contact.*` and
     * anything that drives security-bearing text MUST stay off
     * the list.
     *
     * Note: per-page meta (`title`, `description`) is intentionally
     * NOT in the default allowlist. Page meta lives in the `.md`
     * front-matter — the editor edits it there via the page editor,
     * with `i18n/<lang>.json → meta.<slug>` as a developer-shipped
     * fallback for missing front-matter. A second override path in
     * the overlay would only create ambiguity ("which wins?") for
     * zero gain.
     *
     * Missing overlay file → no merge, behaviour identical to a
     * pre-overlay deployment. Adding the file is the opt-in.
     */
    'i18n_overlay' => [
        'allowed_sections' => ['navigation'],
    ],
];
