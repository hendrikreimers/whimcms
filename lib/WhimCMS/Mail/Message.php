<?php
declare(strict_types=1);

namespace H42\WhimCMS\Mail;

/**
 * Immutable mail value-object. The Mailer composes one of these and
 * hands it to a Transport.
 *
 * Header-injection defence:
 *   The constructor strips \r, \n, and \0 from every header field. The
 *   subject and address fields are the classic injection vectors so we
 *   sanitise on construction rather than at send time, making it
 *   impossible for unsanitised values to slip through later code.
 */
final class Message
{
    public readonly string $to;
    public readonly string $subject;
    public readonly string $fromEmail;
    public readonly string $fromName;
    public readonly ?string $replyTo;
    public readonly ?string $textBody;
    public readonly ?string $htmlBody;

    public function __construct(
        string $to,
        string $subject,
        string $fromEmail,
        string $fromName,
        ?string $replyTo,
        ?string $textBody,
        ?string $htmlBody,
    ) {
        if ($textBody === null && $htmlBody === null) {
            throw new \InvalidArgumentException('Message must have at least one body part.');
        }
        $this->to        = self::stripHeaderUnsafe($to);
        $this->subject   = self::stripHeaderUnsafe($subject);
        $this->fromEmail = self::stripHeaderUnsafe($fromEmail);
        $this->fromName  = self::stripHeaderUnsafe($fromName);
        $this->replyTo   = $replyTo === null ? null : self::stripHeaderUnsafe($replyTo);
        $this->textBody  = $textBody;
        $this->htmlBody  = $htmlBody;
    }

    /** Drop CR/LF/NUL — these are how header injection sneaks in. */
    private static function stripHeaderUnsafe(string $v): string
    {
        return str_replace(["\r", "\n", "\0"], '', $v);
    }
}
