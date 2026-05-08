<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security;

use H42\WhimCMS\Log;

/**
 * Soft IP blocklist with two-stage logic:
 *
 *   1. Strikes  — invalid submissions (honeypot trip, bad token, …)
 *                 increment a counter for the IP. Counters age out of
 *                 their own time window so stale failures don't compound.
 *
 *   2. Block    — once strikes exceed `failThreshold` inside `failWindow`,
 *                 the IP is blocked for `blockDuration`. Future requests
 *                 are denied via isBlocked() without further logic.
 *
 *   Storage:    var/state/blocklist.json   (single file; small enough)
 *               { strikes: { keyhash: [ts1, ts2, …] },
 *                 blocks:  { keyhash: blockExpiryTs } }
 *
 * Auto-cleanup: every read prunes expired entries. The file stays
 * bounded in size by however many distinct IPs are currently active.
 *
 * Like RateLimiter, IPs are stored as HMAC-keyed hashes — never plain.
 */
final class Blocklist
{
    private string $path;
    private string $secret;
    private int $failThreshold;
    private int $failWindow;
    private int $blockDuration;

    public function __construct(string $stateDir, string $secret, int $failThreshold, int $failWindow, int $blockDuration)
    {
        $this->path          = rtrim($stateDir, '/\\') . '/blocklist.json';
        $this->secret        = $secret;
        $this->failThreshold = max(1, $failThreshold);
        $this->failWindow    = max(1, $failWindow);
        $this->blockDuration = max(60, $blockDuration);
    }

    public function isBlocked(string $ip, ?int $now = null): bool
    {
        $now  = $now ?? time();
        $key  = $this->keyFor($ip);
        $data = $this->readPruned($now);
        $exp  = $data['blocks'][$key] ?? 0;
        return $exp > $now;
    }

    /**
     * Record one strike. If the strike threshold is met, the caller is
     * automatically promoted to a block. Returns true if the IP is now
     * (after this strike) blocked.
     */
    public function strike(string $ip, ?int $now = null): bool
    {
        $now = $now ?? time();
        $key = $this->keyFor($ip);

        $this->ensureDir();
        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            Log::error('Blocklist: cannot open state', ['path' => $this->path]);
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            rewind($fh);
            $raw  = stream_get_contents($fh);
            $data = $this->decode($raw === false ? '' : $raw);
            $data = $this->prune($data, $now);

            // Already blocked? No-op.
            $blockedUntil = $data['blocks'][$key] ?? 0;
            if ($blockedUntil > $now) {
                $this->write($fh, $data);
                return true;
            }

            $strikes = $data['strikes'][$key] ?? [];
            $strikes[] = $now;
            // Keep within the strike window.
            $cutoff = $now - $this->failWindow;
            $strikes = array_values(array_filter($strikes, static fn(int $t) => $t >= $cutoff));

            $blocked = false;
            if (count($strikes) >= $this->failThreshold) {
                $data['blocks'][$key] = $now + $this->blockDuration;
                unset($data['strikes'][$key]);
                $blocked = true;
                Log::warn('Blocklist: IP blocked after strikes', ['key' => substr($key, 0, 8) . '…']);
            } else {
                $data['strikes'][$key] = $strikes;
            }

            $this->write($fh, $data);
            return $blocked;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function keyFor(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, $this->secret), 0, 32);
    }

    /** @return array{strikes: array<string, list<int>>, blocks: array<string, int>} */
    private function readPruned(int $now): array
    {
        if (!is_file($this->path)) {
            return ['strikes' => [], 'blocks' => []];
        }
        $raw = @file_get_contents($this->path);
        $data = $this->decode($raw === false ? '' : $raw);
        return $this->prune($data, $now);
    }

    /**
     * @param array{strikes: array<string, list<int>>, blocks: array<string, int>} $data
     * @return array{strikes: array<string, list<int>>, blocks: array<string, int>}
     */
    private function prune(array $data, int $now): array
    {
        $strikeCutoff = $now - $this->failWindow;
        foreach ($data['strikes'] as $k => $list) {
            $kept = array_values(array_filter($list, static fn(int $t) => $t >= $strikeCutoff));
            if ($kept === []) {
                unset($data['strikes'][$k]);
            } else {
                $data['strikes'][$k] = $kept;
            }
        }
        foreach ($data['blocks'] as $k => $exp) {
            if ($exp <= $now) {
                unset($data['blocks'][$k]);
            }
        }
        return $data;
    }

    /** @return array{strikes: array<string, list<int>>, blocks: array<string, int>} */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return ['strikes' => [], 'blocks' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['strikes' => [], 'blocks' => []];
        }
        $strikes = [];
        if (isset($decoded['strikes']) && is_array($decoded['strikes'])) {
            foreach ($decoded['strikes'] as $k => $v) {
                if (is_string($k) && is_array($v)) {
                    $strikes[$k] = array_values(array_map('intval', $v));
                }
            }
        }
        $blocks = [];
        if (isset($decoded['blocks']) && is_array($decoded['blocks'])) {
            foreach ($decoded['blocks'] as $k => $v) {
                if (is_string($k) && is_numeric($v)) {
                    $blocks[$k] = (int)$v;
                }
            }
        }
        return ['strikes' => $strikes, 'blocks' => $blocks];
    }

    /**
     * Atomic write under lock: ftruncate+fwrite on the held fh so the
     * LOCK_EX stays anchored to the same inode across the full read-
     * modify-write. See RateLimiter for the full rationale — tmpfile+
     * rename here would orphan the locked inode and let a concurrent
     * `strike()` overwrite an in-flight update, losing strikes.
     *
     * @param resource $fh
     * @param array{strikes: array<string, list<int>>, blocks: array<string, int>} $data
     */
    private function write($fh, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{"strikes":{},"blocks":{}}';
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $payload);
        fflush($fh);
        @chmod($this->path, 0o600);
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create state dir: {$dir}");
        }
    }
}
