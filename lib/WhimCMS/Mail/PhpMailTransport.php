<?php
declare(strict_types=1);

namespace H42\WhimCMS\Mail;

use H42\WhimCMS\Log;

/**
 * Send mail via PHP's built-in mail() — the simplest backend that works
 * on shared hosting where the host relays through its own MTA.
 *
 * Composes either a single text/plain or text/html message, or a
 * multipart/alternative when both bodies are provided. UTF-8 throughout.
 * Subject is RFC-2047 Q-encoded for non-ASCII characters.
 *
 * For SMTP-based hosts, swap this for an SmtpTransport implementing the
 * same Transport interface — Mailer doesn't care which it gets.
 */
final class PhpMailTransport implements Transport
{
    public function send(Message $message): bool
    {
        $headers = $this->buildHeaders($message);
        [$body, $contentTypeHeader] = $this->buildBody($message);
        $headers[] = $contentTypeHeader;

        $subject = $this->encodeSubject($message->subject);

        // Important: the 4th `$additional_params` argument can pass -f
        // to set the envelope sender, but most shared hosts forbid that.
        // We rely on the From: header and a host-side default envelope.
        $ok = @mail(
            $message->to,
            $subject,
            $body,
            implode("\r\n", $headers)
        );

        if (!$ok) {
            Log::error('Mail transport failed', [
                'to'      => $message->to,
                'subject' => $message->subject,
            ]);
        }
        return $ok;
    }

    /** @return list<string> */
    private function buildHeaders(Message $message): array
    {
        $from = sprintf('%s <%s>',
            $this->encodeHeader($message->fromName),
            $message->fromEmail
        );
        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'X-Mailer: H42-Site',
        ];
        if ($message->replyTo !== null && $message->replyTo !== '') {
            // Defence-in-depth third layer for Reply-To. Validator and
            // Message::stripHeaderUnsafe already filter CR/LF/NUL +
            // quoted-local-parts upstream; this re-validates the bare
            // address shape directly before it lands in a mail header
            // and emits it inside <...> for spec-conformance — a future
            // change introducing a display-name part can no longer
            // produce ambiguous header parsing.
            if (filter_var($message->replyTo, FILTER_VALIDATE_EMAIL) !== false) {
                $headers[] = 'Reply-To: <' . $message->replyTo . '>';
            }
        }
        return $headers;
    }

    /** @return array{0: string, 1: string} body, content-type-header */
    private function buildBody(Message $message): array
    {
        $hasText = $message->textBody !== null;
        $hasHtml = $message->htmlBody !== null;

        if ($hasText && $hasHtml) {
            $boundary = '=_h42_' . bin2hex(random_bytes(8));
            $parts = [];
            $parts[] = "--{$boundary}";
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = (string)$message->textBody;
            $parts[] = "--{$boundary}";
            $parts[] = 'Content-Type: text/html; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = (string)$message->htmlBody;
            $parts[] = "--{$boundary}--";
            return [
                implode("\r\n", $parts),
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];
        }

        if ($hasHtml) {
            return [
                (string)$message->htmlBody,
                'Content-Type: text/html; charset=UTF-8',
            ];
        }

        return [
            (string)$message->textBody,
            'Content-Type: text/plain; charset=UTF-8',
        ];
    }

    /**
     * RFC-2047 Q-encode if the subject contains non-ASCII so transports
     * don't mangle UTF-8.
     */
    private function encodeSubject(string $s): string
    {
        if (preg_match('/[\x80-\xff]/', $s) !== 1) {
            return $s;
        }
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    /** Same encoding for header phrase parts (e.g. From-Name). */
    private function encodeHeader(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (preg_match('/[\x80-\xff]/', $s) !== 1) {
            // Quote if it contains specials per RFC 5322.
            if (preg_match('/[(),:;<>@\\[\\]"\\\\]/', $s) === 1) {
                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
            }
            return $s;
        }
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
}
