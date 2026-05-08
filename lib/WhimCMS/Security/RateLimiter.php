<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security;

use H42\WhimCMS\Log;

/**
 * IP-keyed sliding-window rate limiter, file-backed.
 *
 *   Storage:  var/state/ratelimit/<keyhash>.json
 *   Content:  list of unix timestamps, oldest first.
 *
 * The IP is hashed (HMAC-SHA-256, keyed with the application secret) so
 * the on-disk identifier can't be reversed to an IP — useful both for
 * privacy and to avoid leaking visitor IPs if the file ever escapes.
 *
 * On every check, expired entries (older than $window) are pruned, so
 * the file size stays bounded by the allowed rate.
 */
final class RateLimiter
{
    private string $dir;
    private string $secret;
    private int $window;
    private int $max;

    public function __construct(string $stateDir, string $secret, int $windowSeconds, int $maxPerWindow)
    {
        $this->dir    = rtrim($stateDir, '/\\') . '/ratelimit';
        $this->secret = $secret;
        $this->window = max(1, $windowSeconds);
        $this->max    = max(1, $maxPerWindow);
    }

    /**
     * Atomically register one hit and check whether the IP is still
     * inside its quota. Returns true if the request is allowed,
     * false if it exceeds the rate OR the limiter cannot operate.
     *
     * Fail-mode: **closed**. If the state file cannot be opened
     * (disk full, var/state/ratelimit unwritable, inode quota hit,
     * …) we return false, rejecting the request. The previous
     * fail-open default would silently disable the throttle the
     * moment an attacker found a way to exhaust the state-write
     * surface — for the contact form and admin-login buckets that
     * back this class, locking everyone out for a few minutes while
     * the operator notices the disk-full error is the safer
     * trade-off. The error is logged so the misconfiguration is
     * visible.
     */
    public function hit(string $ip, ?int $now = null): bool
    {
        $now = $now ?? time();
        $path = $this->pathFor($ip);
        try {
            $this->ensureDir();
        } catch (\Throwable $e) {
            Log::error('RateLimiter: cannot create state dir; failing closed', ['error' => $e->getMessage()]);
            return false;
        }

        // Open with create-if-missing, lock for the read-modify-write cycle.
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            Log::error('RateLimiter: cannot open state file; failing closed', ['path' => $path]);
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            rewind($fh);
            $raw = stream_get_contents($fh);
            $entries = $this->decode($raw);

            // Drop expired.
            $cutoff = $now - $this->window;
            $entries = array_values(array_filter($entries, static fn(int $t) => $t >= $cutoff));

            $allowed = count($entries) < $this->max;
            if ($allowed) {
                $entries[] = $now;
            }

            // Persist via ftruncate+fwrite on the held fh — keeps the
            // LOCK_EX anchored to the same inode for the full read-
            // modify-write. tmpfile+rename would atomically replace the
            // inode and leave any concurrent worker's flock pointing at
            // the now-orphaned old inode, producing lost-update races
            // (parallel hits past the window cap, missed strikes, etc.)
            $payload = json_encode($entries, JSON_UNESCAPED_SLASHES) ?: '[]';
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $payload);
            fflush($fh);
            @chmod($path, 0o600);
            return $allowed;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Inspect current count without registering a hit. Useful for
     * background maintenance / debug.
     */
    public function currentCount(string $ip, ?int $now = null): int
    {
        $now = $now ?? time();
        $path = $this->pathFor($ip);
        if (!is_file($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        $entries = $this->decode($raw === false ? '' : $raw);
        $cutoff = $now - $this->window;
        return count(array_filter($entries, static fn(int $t) => $t >= $cutoff));
    }

    private function pathFor(string $ip): string
    {
        $key = hash_hmac('sha256', $ip, $this->secret);
        return $this->dir . '/' . substr($key, 0, 32) . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create rate-limit dir: {$this->dir}");
        }
    }

    /** @return list<int> */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            if (is_int($v) || (is_numeric($v) && ctype_digit((string)$v))) {
                $out[] = (int)$v;
            }
        }
        return $out;
    }
}
