<?php
declare(strict_types=1);

namespace H42\WhimCMS\Mail;

use H42\WhimCMS\Log;

/**
 * Audit log of mail send attempts.
 *
 * Layout:
 *   var/state/mail-log/YYYY-MM-DD/<unix>-<rand>.json
 *
 *   {
 *     ts:        "2026-05-01T12:34:56+02:00",
 *     direction: "recipient" | "sender_confirmation",
 *     status:    "sent" | "failed" | "skipped",
 *     to:        "..." (recipient address),
 *     ip_hash:   "..." (HMAC of submitter IP, never plaintext),
 *     subject:   "...",
 *     body:      "..." (only when log_include_body=true)
 *   }
 *
 * One file per send keeps writes contention-free under bursts and makes
 * day-bucketed retention a directory delete instead of a JSON edit.
 *
 * Retention is NOT enforced here. It used to run inline on every
 * write, which froze the existing records forever the moment an
 * operator set `log_enabled = false` — the pruner lived behind the
 * writer's early return. Day buckets older than
 * `mail.log_retention_days` are now removed by the kernel-wired
 * `Maintenance\DayDirSweeper`, independent of whether this writer
 * still runs.
 */
final class MailLog
{
    private string $dir;

    public function __construct(
        string $stateDir,
        private bool $enabled,
        private bool $includeBody,
    ) {
        $this->dir = rtrim($stateDir, '/\\') . '/mail-log';
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function record(string $direction, string $status, Message $message, ?string $ipHash, array $extra = []): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->ensureDir();
        $now = time();
        $day = date('Y-m-d', $now);
        $dayDir = $this->dir . '/' . $day;
        if (!is_dir($dayDir) && !@mkdir($dayDir, 0700, true) && !is_dir($dayDir)) {
            Log::error('MailLog: cannot create day dir', ['dir' => $dayDir]);
            return;
        }

        $entry = array_merge([
            'ts'        => date(\DateTimeInterface::ATOM, $now),
            'direction' => $direction,
            'status'    => $status,
            'to'        => $message->to,
            'ip_hash'   => $ipHash,
            'subject'   => $message->subject,
        ], $extra);

        if ($this->includeBody) {
            $entry['body'] = $message->textBody ?? $message->htmlBody;
        }

        // 8 random bytes → 64 bits of entropy keeps two simultaneous
        // sends from colliding even on the busiest day.
        $name = sprintf('%d-%s.json', $now, bin2hex(random_bytes(8)));
        $path = $dayDir . '/' . $name;
        @file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        );
        @chmod($path, 0600);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create mail-log dir: {$this->dir}");
        }
    }
}
