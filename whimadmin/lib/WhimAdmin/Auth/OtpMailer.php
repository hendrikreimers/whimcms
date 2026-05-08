<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\View\Renderer;
use H42\WhimCMS\Config as CoreConfig;
use H42\WhimCMS\Log as CoreLog;
use H42\WhimCMS\Mail\Message;
use H42\WhimCMS\Mail\PhpMailTransport;

/**
 * Mails the OTP code to the admin user.
 *
 * Composition:
 *   - Subject:   `<core mail.subject_prefix><whimadmin mail.subject_prefix>Login code: <code>`
 *     (subjects render via the whimadmin view layer too, so the
 *      template owner can reword without touching code)
 *   - Bodies:    rendered from `whimadmin/views/mail/otp.html` (HTML)
 *                and `whimadmin/views/mail/otp-text.html` (plain text)
 *   - From:      core `config/mail.php → from / from_name` (admin
 *                rides the same envelope identity as the public site,
 *                so SPF/DMARC alignment carries over)
 *   - To:        the admin user's email from UserStore
 *
 * Respects core's `mail.enabled` master switch — if mail is globally
 * disabled, the call returns false and the controller treats the
 * login as failed at the mail step, with a uniform error to the user.
 *
 * Does NOT honour the core's `mail.daily_max`: admin login is
 * operational-critical and shouldn't be throttled by contact-form
 * volume. Instead this class enforces its OWN per-day cap, configured
 * via `whimadmin/config/app.php → otp.daily_max`. State is tracked in
 * `whimadmin/var/state/otp-mail-counter/<Y-m-d>.txt` (one counter per
 * UTC day, auto-resets at midnight by virtue of the date-keyed
 * filename). Failure modes mirror the core mailer's daily-cap: fail
 * CLOSED on FS errors so an exhausted state directory can't silently
 * disable the throttle.
 */
final class OtpMailer
{
    public function __construct(
        private Renderer $renderer,
        private PhpMailTransport $transport,
        private AuditLog $audit,
        private string $stateDir,
        private int $dailyMax,
    ) {
    }

    /**
     * Compose + send the OTP. Returns true on send success, false
     * on any failure (mail disabled, transport failed, missing
     * config). The plaintext code is a parameter — callers must
     * have just generated and stored it via OtpStore::issue.
     */
    public function send(string $username, string $toEmail, string $code, string $clientIp): bool
    {
        // Core mail config is loaded by the Kernel via Config::loadDir
        // before this class is constructed.
        if (!CoreConfig::isLoaded()) {
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'core_config_not_loaded']);
            return false;
        }
        if (!(bool)CoreConfig::get('mail.enabled', false)) {
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'mail_disabled']);
            return false;
        }
        if (!$this->underDailyCap($clientIp, $username)) {
            // underDailyCap audit-records its own specific reason
            // ('daily_cap', 'counter_dir', 'counter_file').
            return false;
        }

        $fromEmail = (string)CoreConfig::get('mail.from', '');
        $fromName  = (string)CoreConfig::get('mail.from_name', '');
        if ($fromEmail === '') {
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'mail_from_missing']);
            return false;
        }

        // Compose context. Code goes through the engine's standard
        // HTML escape on `{{ }}` and plain on text-mode renderText —
        // there's no way for the digit string to hit the output
        // unsanitised, but a short-circuit defence: validate the code
        // shape before rendering.
        if (preg_match('/^[0-9]{4,10}$/', $code) !== 1) {
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'bad_code_shape']);
            return false;
        }

        $subjectPrefix = (string)CoreConfig::get('mail.subject_prefix', '');
        $context = [
            'CODE'      => $code,
            'USERNAME'  => $username,
            'TTL_MIN'   => 5, // matches default app config; templates copy
        ];

        try {
            $subject  = trim($subjectPrefix . ' ' . $this->renderer->renderText('mail/otp-subject', $context));
            $textBody = $this->renderer->renderText('mail/otp-text', $context);
            $htmlBody = $this->renderer->render('mail/otp', $context);
        } catch (\Throwable $e) {
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'render_failed']);
            return false;
        }

        $message = new Message(
            to:        $toEmail,
            subject:   $subject,
            fromEmail: $fromEmail,
            fromName:  $fromName,
            replyTo:   null,
            textBody:  $textBody,
            htmlBody:  $htmlBody,
        );

        $ok = $this->transport->send($message);
        $this->audit->record(
            $ok ? 'login.otp.sent' : 'login.otp.send.fail',
            $clientIp,
            $username,
            $ok ? [] : ['reason' => 'transport_failed'],
        );
        return $ok;
    }

    /**
     * Compare today's OTP-send count against `otp.daily_max` and, when
     * still below it, atomically reserve the next slot. Same fail-closed
     * posture as the core Mailer's daily-cap (`Mailer::underDailyCap`):
     * any FS / lock failure logs + audit-records and refuses the send,
     * so an exhausted state directory cannot silently disable the cap.
     *
     * `otp.daily_max = 0` is the documented opt-out (no cap).
     */
    private function underDailyCap(string $clientIp, string $username): bool
    {
        if ($this->dailyMax <= 0) {
            return true; // explicitly uncapped
        }
        $dir = rtrim($this->stateDir, '/\\') . '/otp-mail-counter';
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            CoreLog::error('OtpMailer: cannot create counter dir; failing closed', ['dir' => $dir]);
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'counter_dir']);
            return false;
        }
        $path = $dir . DIRECTORY_SEPARATOR . gmdate('Y-m-d') . '.txt';

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            CoreLog::error('OtpMailer: cannot open counter file; failing closed', ['path' => $path]);
            $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'counter_file']);
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            rewind($fh);
            $raw = stream_get_contents($fh);
            $count = is_string($raw) ? (int)trim($raw) : 0;
            if ($count >= $this->dailyMax) {
                $this->audit->record('login.otp.send.fail', $clientIp, $username, ['reason' => 'daily_cap']);
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
            @chmod($path, 0o600);
        }
    }
}
