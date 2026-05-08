<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

/**
 * Per-user block clipboard. Single block at a time (cut → paste).
 *
 *   File:    whimadmin/var/state/clipboard/<userKey>.json
 *   userKey: short HMAC of the username (same posture as OtpStore)
 *   Format:  serialised Block (type, attrs, body)
 *
 * Entries have no TTL — they live until the operator explicitly cuts
 * a different block or pastes (which clears) or signs out (no
 * deletion at logout in v1; the file is harmless and overwritten on
 * the next cut). Phase 6+ may add an expiry sweeper.
 */
final class ClipboardStore
{
    private string $dir;

    public function __construct(
        private string $stateDir,
        private string $secret,
    ) {
        $this->dir = rtrim($stateDir, '/\\') . '/clipboard';
    }

    public function set(string $username, Block $block): void
    {
        $this->ensureDir();
        $payload = [
            'type'  => $block->type,
            'attrs' => $block->attrs,
            'body'  => $block->body,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $path = $this->pathFor($username);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    public function get(string $username): ?Block
    {
        $path = $this->pathFor($username);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $type  = is_string($decoded['type']  ?? null) ? $decoded['type']  : null;
        $attrs = is_array ($decoded['attrs'] ?? null) ? $decoded['attrs'] : null;
        $body  = $decoded['body'] ?? null;
        $body  = is_string($body) || $body === null ? $body : null;
        if ($type === null || $attrs === null) {
            return null;
        }
        return new Block(type: $type, attrs: $attrs, body: $body);
    }

    public function clear(string $username): void
    {
        $path = $this->pathFor($username);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function has(string $username): bool
    {
        return is_file($this->pathFor($username));
    }

    private function pathFor(string $username): string
    {
        $key = substr(hash_hmac('sha256', $username, $this->secret), 0, 32);
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create clipboard dir: {$this->dir}");
        }
    }
}
