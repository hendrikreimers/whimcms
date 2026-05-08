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
];
