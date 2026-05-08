<?php
declare(strict_types=1);

/**
 * Spam-scraper defence for emails rendered in the page.
 *
 * When `enabled`, every path listed in `paths` is split into user /
 * domain parts and rendered as a non-clickable obfuscated string
 * (e.g. "hello [at] example.com") backed by a `<span class="js-email"
 * data-u="..." data-d="...">`. js/email.js rehydrates the span into a
 * working mailto link at page load, so humans never notice while bots
 * without JS get nothing usable.
 *
 *   format     Replacement string. Supports %user% and %domain%.
 *   paths      Map of context-key → dot-path inside CURRENT_LANG.
 *              Each entry exposes the obfuscated struct as
 *              EMAIL.<key> in the render context.
 */

return [
    'email_protection' => [
        'enabled' => true,
        'format'  => '%user% [at] %domain%',
        'paths'   => [
            'contact' => 'home.contact.email',
        ],
    ],
];
