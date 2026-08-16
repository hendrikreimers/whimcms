<?php
declare(strict_types=1);

namespace H42\WhimCMS\Security\Form\Captcha;

use H42\WhimCMS\Io\FileRewrite;
use H42\WhimCMS\Log;
use H42\WhimCMS\Security\ThrottleKey;

/**
 * Per-IP counter for captcha-missing submissions.
 *
 * The contact pipeline treats an empty captcha nonce/token as a
 * usability fault, not bot behaviour — that's correct for legitimate
 * visitors on browsers without SubtleCrypto. Without any throttling,
 * though, a bot that simply omits the captcha fields can submit up to
 * the rate-limit ceiling per IP-window without ever earning a strike,
 * never reaching the soft block.
 *
 * This tracker closes that gap: each captcha-missing submission is
 * recorded in a sliding window per IP. When the count reaches the
 * configured threshold, the controller escalates the next miss to a
 * regular Blocklist::strike(). A real human who hits the threshold
 * once has to retry from a different network, but they would have to
 * fail at least N times in the window first — high enough to not
 * affect normal usage on transient browser issues.
 *
 *   Storage:  var/state/captcha-miss/<keyhash>.json
 *   Content:  list of unix timestamps, oldest first.
 *
 * IPs are HMAC-keyed with the application secret, never stored
 * plaintext — same posture as RateLimiter / Blocklist.
 */
final class CaptchaMissTracker
{
    private string $dir;
    private string $secret;
    private int $window;
    private int $threshold;

    public function __construct(string $stateDir, string $secret, int $windowSeconds, int $threshold)
    {
        $this->dir       = rtrim($stateDir, '/\\') . '/captcha-miss';
        $this->secret    = $secret;
        $this->window    = max(1, $windowSeconds);
        $this->threshold = max(1, $threshold);
    }

    /**
     * Register one miss for $ip. Returns true if the miss count after
     * this insertion has reached the configured threshold (caller
     * should escalate to a Blocklist strike).
     *
     * Fails open on state-FILE errors (open, lock, rewrite) —
     * deliberately the OPPOSITE of RateLimiter's fail-closed posture
     * (an earlier comment here claimed "same posture", which was
     * wrong). Why open is right for this class: in the contact
     * pipeline `RateLimiter::hit()` runs BEFORE any captcha handling
     * and fails closed, so on a broken filesystem every submission is
     * already rejected upstream and no abuse reaches this tracker.
     * All failing open costs is one uncounted miss — whereas failing
     * closed would mint blocklist strikes against legitimate visitors
     * for a disk fault.
     *
     * One deliberate exception: an uncreatable state DIRECTORY throws
     * from ensureDir() and surfaces as a 500 — that is a deployment
     * fault (var/ unwritable), not a runtime hiccup, and hiding it
     * would leave the operator blind.
     */
    public function bumpAndExceeded(string $ip, ?int $now = null): bool
    {
        $now = $now ?? time();
        $this->ensureDir();
        $path = $this->pathFor($ip);

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            Log::error('CaptchaMissTracker: cannot open state file', ['path' => $path]);
            return false;
        }
        try {
            // Unacquirable lock → miss not counted (open posture above).
            if (!flock($fh, LOCK_EX)) {
                Log::error('CaptchaMissTracker: cannot acquire lock; miss not counted', ['path' => $path]);
                return false;
            }
            rewind($fh);
            $raw = stream_get_contents($fh);
            $entries = $this->decode($raw === false ? '' : $raw);

            $cutoff = $now - $this->window;
            $entries = array_values(array_filter($entries, static fn(int $t) => $t >= $cutoff));
            $entries[] = $now;

            // Rewrite on the held fh keeps the lock anchored to the
            // same inode for the full read-modify-write (see
            // FileRewrite). Verified: a short write restores the
            // previous window instead of silently resetting it. On
            // failure this miss goes uncounted — the class's open
            // posture (see bumpAndExceeded docblock).
            $payload = json_encode($entries, JSON_UNESCAPED_SLASHES) ?: '[]';
            if (!FileRewrite::replace($fh, $payload, $raw === false ? '' : $raw)) {
                Log::error('CaptchaMissTracker: state rewrite failed; miss not counted', ['path' => $path]);
                return false;
            }
            @chmod($path, 0o600);
            return count($entries) >= $this->threshold;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Same key derivation as `Blocklist` — mandatory, not cosmetic.
     * This tracker escalates into that store, so both must agree on
     * which client a strike belongs to. See `ThrottleKey`.
     */
    private function pathFor(string $ip): string
    {
        return $this->dir . '/' . ThrottleKey::derive($ip, $this->secret) . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create captcha-miss dir: {$this->dir}");
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
