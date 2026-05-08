<?php
declare(strict_types=1);

namespace H42\WhimCMS\Mail;

/**
 * Strategy interface for delivering an already-composed Message.
 *
 * The current implementation is PhpMailTransport (built-in mail()),
 * which is enough for shared hosting where the host relays via the
 * platform's MTA. Adding SMTP later means dropping in a new Transport
 * (e.g. SmtpTransport via PHPMailer/Symfony Mailer) without touching
 * the orchestrator.
 */
interface Transport
{
    /**
     * Send one message. Returns true on apparent success — note that
     * mail() returning true only means the local MTA accepted the
     * message, not that the recipient received it.
     *
     * Implementations MUST NOT throw on common transient failures —
     * return false so the caller can record the failure and continue.
     */
    public function send(Message $message): bool;
}
