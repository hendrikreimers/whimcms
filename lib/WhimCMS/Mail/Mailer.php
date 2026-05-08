<?php
declare(strict_types=1);

namespace H42\WhimCMS\Mail;

use H42\WhimCMS\Log;
use H42\WhimCMS\Template\Engine;

/**
 * Composes mail messages from templates + render context, applies the
 * configured transport, and records the outcome via MailLog. Also owns
 * the per-day hard-cap so a misconfigured form can't drain the host's
 * mail quota.
 *
 * Templates are run through the same template engine as the site, so
 * %CURRENT_LANG.…%, %submission.…%, %URLS.…%, etc. all resolve naturally
 * inside mail content.
 */
final class Mailer
{
    /**
     * @param array<string, mixed> $config  config('mail') section
     */
    public function __construct(
        private Engine $engine,
        private Transport $transport,
        private MailLog $log,
        private string $stateDir,
        private array $config,
    ) {
    }

    /**
     * Compose + send the recipient notification (to the site owner).
     *
     * @param array<string, mixed> $context  render context for templates
     */
    public function sendRecipientMail(array $context, string $ipHash, string $submitterEmail): bool
    {
        if (!($this->config['enabled'] ?? false)) {
            $this->log->record('recipient', 'skipped', $this->stub(), $ipHash, ['reason' => 'mail_disabled']);
            return false;
        }
        if (!$this->underDailyCap()) {
            Log::warn('Mailer: daily cap exceeded; refusing send');
            $this->log->record('recipient', 'skipped', $this->stub(), $ipHash, ['reason' => 'daily_cap']);
            return false;
        }

        $subject = trim((string)($this->config['subject_prefix'] ?? '')) . ' '
            . ($this->renderSubject('mail/contact-recipient-subject', $context) ?: 'New contact submission');

        $message = new Message(
            to:        (string)($this->config['recipient'] ?? ''),
            subject:   $subject,
            fromEmail: (string)($this->config['from'] ?? ''),
            fromName:  (string)($this->config['from_name'] ?? ''),
            replyTo:   ($this->config['reply_to_sender'] ?? false) ? $submitterEmail : null,
            textBody:  ($this->config['send_txt']  ?? false) ? $this->engine->renderText('mail/contact-recipient', $context) : null,
            htmlBody:  ($this->config['send_html'] ?? false) ? $this->engine->render('mail/contact-recipient-html', $context) : null,
        );

        $ok = $this->transport->send($message);
        $this->log->record('recipient', $ok ? 'sent' : 'failed', $message, $ipHash);
        return $ok;
    }

    /**
     * Optional confirmation back to the submitter.
     *
     * Defence-in-depth: the submitter-supplied name is hardened (letters /
     * marks / spaces / hyphens / apostrophes only, dots only between letters,
     * 40 chars max) before it lands in the render context so even a future
     * template change can't accidentally reintroduce a phishing-friendly
     * "Hi <attacker text>," pattern. The current sender templates don't
     * render the name at all, by design.
     */
    public function sendSenderConfirmation(array $context, string $ipHash, string $submitterEmail): bool
    {
        if (!($this->config['enabled'] ?? false)) {
            return false;
        }
        if (!($this->config['send_confirmation_to_sender'] ?? false)) {
            return false;
        }
        if (!$this->underDailyCap()) {
            Log::warn('Mailer: daily cap exceeded; sender confirmation skipped');
            return false;
        }

        $senderCtx = $context;
        if (isset($senderCtx['submission']) && is_array($senderCtx['submission'])) {
            $rawName = is_string($senderCtx['submission']['name'] ?? null)
                ? (string)$senderCtx['submission']['name']
                : '';
            $senderCtx['submission']['name'] = self::sanitizeNameForSender($rawName);
        }

        $subject = trim((string)($this->config['subject_prefix'] ?? '')) . ' '
            . ($this->renderSubject('mail/contact-sender-subject', $senderCtx) ?: 'Thanks for your message');

        $message = new Message(
            to:        $submitterEmail,
            subject:   $subject,
            fromEmail: (string)($this->config['from'] ?? ''),
            fromName:  (string)($this->config['from_name'] ?? ''),
            replyTo:   null,
            textBody:  ($this->config['send_txt']  ?? false) ? $this->engine->renderText('mail/contact-sender', $senderCtx) : null,
            htmlBody:  ($this->config['send_html'] ?? false) ? $this->engine->render('mail/contact-sender-html', $senderCtx) : null,
        );

        $ok = $this->transport->send($message);
        $this->log->record('sender_confirmation', $ok ? 'sent' : 'failed', $message, $ipHash);
        return $ok;
    }

    /**
     * Strict whitelist for any submitter-supplied name that ends up in a
     * mail going *to* the submitter. Defence in depth — the sender
     * templates currently don't render the name at all; this guard kicks
     * in if a future change reintroduces it.
     *
     * Allowed: Unicode letters and combining marks, spaces, hyphens,
     * apostrophes (ASCII + curly), dots ONLY between letters.
     * Disallowed: digits, slashes, colons, URL-ish tokens, control bytes.
     * Capped at 40 characters.
     */
    public static function sanitizeNameForSender(string $raw): string
    {
        $clean = preg_replace('/[^\p{L}\p{M} \-\.\'\x{2019}]/u', '', $raw) ?? '';
        // Drop dots that aren't between letters (no leading/trailing/standalone dots).
        $clean = preg_replace('/(?<![\p{L}\p{M}])\.|\.(?![\p{L}\p{M}])/u', '', $clean) ?? $clean;
        // Collapse whitespace, trim.
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
        return mb_substr($clean, 0, 40, 'UTF-8');
    }

    /**
     * Compare today's send count against the configured cap and, if
     * we're still below it, increment the counter atomically.
     *
     * Bookkeeping lives in its own file so the cap works regardless of
     * whether the audit log is enabled — `mail.log_enabled = false`
     * shouldn't accidentally bypass the daily quota that protects host
     * mail limits.
     *
     * Returns true if the send is allowed (and reserves a slot).
     */
    private function underDailyCap(): bool
    {
        $cap = (int)($this->config['daily_max'] ?? 0);
        if ($cap <= 0) {
            // Cap not configured → no limit applies; allow.
            return true;
        }
        $dir = rtrim($this->stateDir, '/\\') . '/mail-counter';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            // Counter dir unavailable — fail CLOSED. The previous
            // fail-open default meant an attacker who exhausted the
            // state directory's write surface (disk full, inode
            // quota) could silently disable the daily-mail cap and
            // turn the contact form into a flooding amplifier. The
            // failure is logged so the operator notices.
            \H42\WhimCMS\Log::error('Mailer: cannot create counter dir; failing closed (no mail sent)', ['dir' => $dir]);
            return false;
        }
        $path = $dir . '/' . date('Y-m-d') . '.txt';

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            \H42\WhimCMS\Log::error('Mailer: cannot open counter file; failing closed (no mail sent)', ['path' => $path]);
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            rewind($fh);
            $raw = stream_get_contents($fh);
            $count = is_string($raw) ? (int)trim($raw) : 0;

            if ($count >= $cap) {
                return false;
            }
            $count++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)$count);
            fflush($fh);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
            @chmod($path, 0600);
        }
    }

    /**
     * Render a one-line subject template; trim and collapse whitespace.
     * Missing template returns ''.
     */
    private function renderSubject(string $name, array $ctx): string
    {
        try {
            // Text-mode render: subjects aren't HTML, so stripping HTML
            // entities through htmlspecialchars would only mangle them.
            $rendered = $this->engine->renderText($name, $ctx);
        } catch (\Throwable) {
            return '';
        }
        $rendered = trim(preg_replace('/\s+/', ' ', $rendered) ?? '');
        return str_replace(["\r", "\n"], ' ', $rendered);
    }

    /** Used purely as a placeholder when we record a "skipped" event. */
    private function stub(): Message
    {
        return new Message(
            to:        (string)($this->config['recipient'] ?? 'unknown@local'),
            subject:   '(skipped)',
            fromEmail: (string)($this->config['from'] ?? 'unknown@local'),
            fromName:  (string)($this->config['from_name'] ?? ''),
            replyTo:   null,
            textBody:  '(no body)',
            htmlBody:  null,
        );
    }
}
