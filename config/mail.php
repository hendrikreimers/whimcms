<?php
declare(strict_types=1);

/**
 * Outbound mail: recipient address, sender envelope, audit-log knobs,
 * and a per-day hard cap that protects against runaway sends.
 */

return [
    'mail' => [
        /**
         * Master switch for outbound mail. **Default: false** — opt-in.
         *
         * A fresh deploy (or fork) starts with mail disabled so a wrong
         * `recipient` / `from` value cannot accidentally send mail to a
         * placeholder address before the operator has reviewed this file.
         * Submissions still go through every gate (CSRF, captcha, validator)
         * and the visitor sees a clear `mail_failed` banner — that's the
         * signal to come here, set the addresses, and flip this to true.
         *
         * Set to true only after `recipient` and `from` are pointed at
         * mailboxes you actually own and the host's MTA can deliver from
         * `from`'s domain (SPF/DKIM/DMARC).
         */
        'enabled'                     => false,
        'recipient'                   => 'demo@example.com',
        'from'                        => 'noreply@example.com',
        'from_name'                   => 'WhimCMS',
        'subject_prefix'              => '[WhimCMS] ',
        'send_html'                   => true,    // include HTML alt-part
        'send_txt'                    => true,    // include plain-text alt-part
        'send_confirmation_to_sender' => true,
        'reply_to_sender'             => true,    // Reply-To = submitter's email
        'daily_max'                   => 50,      // hard cap to protect host quotas

        /**
         * Audit log of every send attempt — date-bucketed, auto-pruned.
         * Body is omitted by default for privacy.
         *
         * OFF by default. Turning it ON makes the log record the
         * submitter email in plaintext (recipient mails do not store
         * the submitter, but sender-confirmation entries do — `to`
         * is the visitor's address). Keeping the log off is the
         * cleanest data-minimisation posture (GDPR Art. 5(1)(c));
         * turn it ON only if you actually need the audit trail, and
         * pair it with a privacy-policy mention. Retention is 7 days
         * either way so even when ON the window stays tight.
         */
        'log_enabled'           => false,
        'log_retention_days'    => 7,
        'log_include_body'      => false,
    ],
];
