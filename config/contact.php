<?php
declare(strict_types=1);

/**
 * Contact-form field schema and the honeypot-field strategy.
 *
 * Field-level CSRF / captcha / rate-limit / blocklist knobs live in
 * config/security.php — this file only describes the form's user-
 * facing fields and the honeypot.
 */

return [
    'contact' => [
        /**
         * Master switch for the contact-form pipeline.
         *
         *   true  (default): POST /contact runs through CSRF / honeypot /
         *     captcha / validator / mailer as normal.
         *   false: POST /contact returns 404 immediately, before any
         *     gate runs (no log entry, no captcha strike, no rate-limit
         *     bucket consumed). Use this when the site has no contact
         *     form at all — defends against bots that POST directly to
         *     `/contact` knowing the WhimCMS endpoint.
         *
         * NOTE: this only blocks POSTs. To stop the form from being
         * rendered as well, remove the `contact` block from
         * content/<lang>/home.md (or whichever page hosts it). The block
         * engine has no awareness of this flag — that's deliberate, the
         * operator decides what's on the page via content, not config.
         *
         * Defence-in-depth: even with this flag true, mail.enabled = false
         * (config/mail.php) keeps the mailer from actually sending —
         * useful for staging environments that should accept submissions
         * without delivering them.
         */
        'enabled' => true,

        /**
         * Pages whose POST is routed to the contact pipeline.
         *
         * The WhimCMS Kernel only hands a POST to the contact handler
         * when the resolved page slug is in this list; the handler then
         * binds its PRG redirect / success URL / error re-render to that
         * resolved page (so /contact and the camp landing round-trip to
         * themselves, not to home).
         *
         * Default (key absent): ['home'] — the historical behaviour.
         * Every pipeline gate (CSRF / captcha / honeypot / rate-limit /
         * the `enabled` switch above) is unchanged and still applies on
         * every listed page; this only widens *which* page accepts the
         * already-gated POST. Slugs come from the validated route table,
         * never from raw request input.
         */
        'post_slugs' => ['contact'],

        /**
         * Per-field validation rules. Keys are HTML form input names.
         * Values are rule arrays — keep types/orders below in sync with
         * lib/WhimCMS/Validator.php.
         *
         *   required   bool      Field must be present and non-empty.
         *   type       string    'text' | 'email' | 'tel' | 'select' | 'checkbox'
         *   min        int       Minimum length (text/textarea).
         *   max        int       Maximum length / trim cap.
         *   pattern    string    Optional regex (validated server-side).
         *   allowed    list      For type='select': whitelist of allowed values.
         *   multiline  bool      Text fields only. true = keep \n / \t
         *                        (textarea-style); false (default) = collapse
         *                        to spaces. Set true ONLY for genuine multi-
         *                        line bodies; leave false for any single-line
         *                        text whose value could later land in a mail
         *                        header sink — the validator removes the
         *                        header-injection primitive at the source.
         *   label      string    i18n path for human-readable error labels.
         */
        'fields' => [
            'name' => [
                'required' => true,
                'type'     => 'text',
                'min'      => 2,
                'max'      => 80,
            ],
            'email' => [
                'required' => true,
                'type'     => 'email',
                'max'      => 200,
            ],
            'message' => [
                'required'  => true,
                'type'      => 'text',
                'min'       => 10,
                'max'       => 5000,
                'multiline' => true,
            ],
            'consent' => [
                'required' => true,
                'type'     => 'checkbox',
            ],
        ],

        /**
         * Honeypot field name.
         *
         *   null (default): the field name is auto-derived from the
         *     application secret via H42\WhimCMS\Honeypot::deriveFieldName()
         *     — random-looking but stable, automatic, and per-installation
         *     unique. This is the recommended setting: bot dictionaries
         *     of common honeypot names ("website", "url", "phone") get
         *     no signal, and rotating the secret rotates the name.
         *
         *   string override: a literal field name. Use only when an
         *     external integration needs a fixed value. Keep it
         *     alphanumeric + underscore; anything else risks HTML-attribute
         *     escaping problems.
         */
        'honeypot_field' => null,
    ],
];
