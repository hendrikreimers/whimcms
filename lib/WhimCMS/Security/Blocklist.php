<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security;

use H42\WhimCMS\Io\FileRewrite;
use H42\WhimCMS\Log;

/**
 * Soft IP blocklist with two-stage logic:
 *
 *   1. Strikes  — invalid submissions (honeypot trip, failed or
 *                 replayed captcha, …) increment a counter for the IP.
 *                 Counters age out of their own time window so stale
 *                 failures don't compound.
 *
 *                 Every caller sits BEHIND a passed CSRF check, so a
 *                 strike always identifies a client that presented a
 *                 token issued to itself. A failed token deliberately
 *                 does NOT strike — see ContactController step 2 for
 *                 why that one is different from all the others.
 *
 *   2. Block    — once strikes exceed `failThreshold` inside `failWindow`,
 *                 the IP is blocked for `blockDuration`. Future requests
 *                 are denied via isBlocked() without further logic.
 *
 *   Storage:    var/state/blocklist.json   (single file; small enough)
 *               { strikes: { keyhash: [ts1, ts2, …] },
 *                 blocks:  { keyhash: blockExpiryTs } }
 *
 * Auto-cleanup, stated precisely because the obvious summary is wrong:
 * strike() prunes expired entries AND writes the pruned state back, so
 * under ongoing traffic the file holds live entries only. isBlocked()
 * prunes for its own answer ONLY — it never writes. A file that saw a
 * burst and then went quiet therefore keeps its high-water mark until
 * the next strike, however many months later, and every contact POST
 * pays a full read + json_decode of entries that are all dead.
 * Kernel::bootstrapMaintenance closes that with a TtlFileSweeper which
 * deletes the whole file once every entry in it is provably expired
 * (TTL = max(fail_window, block_duration) + margin — a block stores an
 * expiry in the future, so the strike window alone is not enough).
 *
 * Like RateLimiter, IPs are stored as HMAC-keyed hashes — never plain.
 *
 * Failure posture: **open — deliberately, and unlike RateLimiter.**
 * `isBlocked()` treats an unreadable state file as "not blocked";
 * `strike()` gives up (logged) when the file can't be opened or the
 * rewrite fails, keeping the previous state. This asymmetry with the
 * fail-closed RateLimiter is a decision, not drift: the blocklist is
 * an ACCELERATOR for abuse response, not the primary gate — CSRF,
 * captcha and the rate limiter all sit in front of it, and the rate
 * limiter already fails closed on the same broken filesystem, so
 * abuse doesn't pass either way. Failing closed here would instead
 * lock every legitimate visitor out on a disk hiccup (self-DoS) for
 * no additional protection. Do not "harmonise" the two postures.
 *
 * Scope of that argument, stated precisely because it does not cover
 * everything this file holds: it justifies the STRIKE path. It does
 * NOT justify what happens to blocks already earned — a corrupt or
 * unreadable blocklist.json lifts every active block at once, and
 * that implies nothing about ratelimit/ being unwritable too. The
 * accepted cost is that a filesystem fault resets the accelerator,
 * not that it opens a gate.
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
            // Unacquirable lock → skip the strike (open posture, see
            // class docblock) instead of running an unprotected RMW.
            if (!flock($fh, LOCK_EX)) {
                Log::error('Blocklist: cannot acquire lock; strike skipped', ['path' => $this->path]);
                return false;
            }
            rewind($fh);
            $raw  = stream_get_contents($fh);
            $raw  = $raw === false ? '' : $raw;
            $data = $this->decode($raw);
            $data = $this->prune($data, $now);

            // Already blocked? No-op.
            $blockedUntil = $data['blocks'][$key] ?? 0;
            if ($blockedUntil > $now) {
                $this->write($fh, $data, $raw);
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

            $this->write($fh, $data, $raw);
            return $blocked;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function keyFor(string $ip): string
    {
        return ThrottleKey::derive($ip, $this->secret);
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
     * Verified write under lock, on the held fh so the LOCK_EX stays
     * anchored to the same inode across the full read-modify-write
     * (tmpfile+rename would orphan concurrent lockers; see
     * FileRewrite). On a short write the PREVIOUS state is restored —
     * this file carries every strike and every active block, so the
     * old truncate-first sequence let a full disk silently erase all
     * of them. A lost single strike (logged) is the accepted open
     * posture; a wiped blocklist is not.
     *
     * @param resource $fh
     * @param array{strikes: array<string, list<int>>, blocks: array<string, int>} $data
     */
    private function write($fh, array $data, string $previousRaw): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{"strikes":{},"blocks":{}}';
        if (!FileRewrite::replace($fh, $payload, $previousRaw)) {
            Log::error('Blocklist: state rewrite failed; keeping previous state, this update is lost', ['path' => $this->path]);
            return;
        }
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
