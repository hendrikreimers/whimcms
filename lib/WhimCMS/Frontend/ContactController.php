<?php
declare(strict_types=1);

namespace H42\WhimCMS\Frontend;

use H42\WhimCMS\Form\Validator;
use H42\WhimCMS\Log;
use H42\WhimCMS\Mail\Mailer;
use H42\WhimCMS\Mail\MailLog;
use H42\WhimCMS\Mail\PhpMailTransport;
use H42\WhimCMS\Security\Blocklist;
use H42\WhimCMS\Security\Form\Captcha\Captcha;
use H42\WhimCMS\Security\Form\Captcha\CaptchaMissTracker;
use H42\WhimCMS\Security\Form\Captcha\CaptchaStore;
use H42\WhimCMS\Security\Form\Csrf;
use H42\WhimCMS\Security\Form\Honeypot;
use H42\WhimCMS\Security\RateLimiter;
use H42\WhimCMS\Security\Secret;
use H42\WhimCMS\Template\Engine;

/**
 * Orchestrates a contact-form POST.
 *
 * Pipeline:
 *   1. Reject if the IP is already on the soft blocklist.
 *   2. Validate the CSRF/timing token. Failure → strike + reject.
 *   3. Rate-limit (5/10min default). Failure → reject (no strike).
 *   4. Inspect honeypot. Filled → strike + pretend success (for bots).
 *   5. Run field validation. Errors → return rerender with errors.
 *   6. Build mail context + send recipient mail (and sender if enabled).
 *   7. Issue a 303-style redirect token to /<lang>/?sent=1#contact.
 *
 * Output shape:
 *   ['action' => 'redirect',  'url' => '...']
 *   ['action' => 'rerender',  'errors' => [...], 'values' => [...], 'global_error' => '...?']
 *   ['action' => 'silent_ok'] // honeypot trip — pretend success
 */
final class ContactController
{
    /**
     * Form-scope identifier baked into every CSRF token issued for the
     * contact form. RenderContext::build issues with this value; we
     * validate against it. Distinct strings per future controller (e.g.
     * 'booking') prevent token confusion if a second POST endpoint is
     * ever added — see Csrf class docstring.
     */
    public const FORM_ID = 'contact';

    public function __construct(
        private Engine $engine,
        private RateLimiter $rateLimiter,
        private Blocklist $blocklist,
        private Validator $validator,
        private Mailer $mailer,
        private CaptchaStore $captchaStore,
        private CaptchaMissTracker $captchaMissTracker,
        private string $secret,
        private string $honeypotField,
        private int $csrfMinAge,
        private int $csrfMaxAge,
        private bool $captchaEnabled,
        private int $captchaMaxAge,
    ) {
    }

    /**
     * @param array<string, mixed> $post           Raw $_POST payload.
     * @param \Closure             $ctxFactory     Lazy render-context builder.
     *                                             Invoked only once validation
     *                                             passes and we're about to
     *                                             render mail templates — bot
     *                                             traffic that fails earlier
     *                                             (block, CSRF, rate-limit,
     *                                             honeypot, captcha, validation)
     *                                             never pays the build cost.
     *                                             Must return an array suitable
     *                                             for the template engine.
     * @param string               $bindKey        Same client-binding key the form's
     *                                             CSRF token was issued with
     *                                             (Csrf::deriveBindKey).
     * @return array<string, mixed>
     */
    public function handle(array $post, \Closure $ctxFactory, string $clientIp, string $bindKey, string $successUrl): array
    {
        // 1. Hard block?
        if ($this->blocklist->isBlocked($clientIp)) {
            return [
                'action' => 'rerender',
                'errors' => [],
                'values' => [],
                'global_error' => 'blocked',
            ];
        }

        // 2. CSRF / timing — token must match the same client (IP + UA)
        // it was issued under AND the same form scope (FORM_ID), so a
        // token harvested from one network or issued for a different
        // form cannot be replayed here.
        $token = is_string($post['_token'] ?? null) ? (string)$post['_token'] : '';
        if (!Csrf::validate($token, $this->secret, $bindKey, self::FORM_ID, $this->csrfMinAge, $this->csrfMaxAge)) {
            $this->blocklist->strike($clientIp);
            Log::info('Contact: invalid token', []);
            return [
                'action' => 'rerender',
                'errors' => [],
                'values' => $this->keepValues($post),
                'global_error' => 'token',
            ];
        }

        // 3. Rate limit (only after token check so bots can't burn through)
        if (!$this->rateLimiter->hit($clientIp)) {
            Log::info('Contact: rate limit hit', []);
            return [
                'action' => 'rerender',
                'errors' => [],
                'values' => $this->keepValues($post),
                'global_error' => 'rate_limit',
            ];
        }

        // 4. Honeypot — bots fill it, humans don't see it
        $honey = $post[$this->honeypotField] ?? '';
        if (is_string($honey) && trim($honey) !== '') {
            $this->blocklist->strike($clientIp);
            Log::info('Contact: honeypot tripped', []);
            // Lie to the bot — return "success" to drain its retry budget
            return ['action' => 'silent_ok', 'url' => $successUrl];
        }

        // 5. Proof-of-work captcha — verifies the client paid the CPU
        // cost of finding a valid nonce for the issued challenge.
        // Bots that scrape forms without running JS hit this gate and
        // get a strike; humans never see it (JS solves transparently).
        if ($this->captchaEnabled) {
            $cToken = is_string($post['_captcha_token'] ?? null) ? (string)$post['_captcha_token'] : '';
            $cNonce = is_string($post['_captcha_nonce'] ?? null) ? (string)$post['_captcha_nonce'] : '';

            // Empty nonce/token usually means the JS solver never ran
            // (browser without SubtleCrypto on a non-secure context) —
            // a real usability fault. We still record the miss in a
            // per-IP sliding window so a bot that simply omits these
            // fields cannot grind through the rate-limit ceiling
            // indefinitely: once misses pass the configured threshold,
            // the next one escalates to a regular Blocklist strike.
            // Legitimate users on a transient browser issue retry a
            // couple of times and stay under the threshold.
            if ($cToken === '' || $cNonce === '') {
                $exceeded = $this->captchaMissTracker->bumpAndExceeded($clientIp);
                if ($exceeded) {
                    $this->blocklist->strike($clientIp);
                    Log::info('Contact: captcha-missing threshold exceeded; strike', []);
                } else {
                    Log::info('Contact: captcha missing (likely unsupported browser)', []);
                }
                return [
                    'action' => 'rerender',
                    'errors' => [],
                    'values' => $this->keepValues($post),
                    'global_error' => 'captcha',
                ];
            }
            if (!Captcha::validate($cToken, $cNonce, $this->secret, $this->captchaMaxAge)) {
                $this->blocklist->strike($clientIp);
                Log::info('Contact: captcha invalid', []);
                return [
                    'action' => 'rerender',
                    'errors' => [],
                    'values' => $this->keepValues($post),
                    'global_error' => 'captcha',
                ];
            }
            // Single-use enforcement: mark the (token, nonce) pair as
            // consumed. A repeat within max_age = replay attempt → strike.
            if (!$this->captchaStore->consume($cToken, $cNonce)) {
                $this->blocklist->strike($clientIp);
                Log::info('Contact: captcha replay', []);
                return [
                    'action' => 'rerender',
                    'errors' => [],
                    'values' => $this->keepValues($post),
                    'global_error' => 'captcha',
                ];
            }
        }

        // 6. Field validation
        $result = $this->validator->validate($post);
        if ($result['errors'] !== []) {
            return [
                'action' => 'rerender',
                'errors' => $result['errors'],
                'values' => $result['values'],
                'global_error' => null,
            ];
        }

        // 7. Send mail. Build the render context lazily — only valid
        // submissions reach this branch, so bot traffic that fails any
        // earlier check above never pays for token + captcha + SEO + JSON-LD
        // generation it would not consume.
        $values = $result['values'];
        $ipHash = $this->hashIp($clientIp);
        $ctx = $ctxFactory();
        $mailCtx = array_merge($ctx, ['submission' => $values]);

        $emailAddr = is_string($values['email'] ?? null) ? (string)$values['email'] : '';
        $sentToOwner = $this->mailer->sendRecipientMail($mailCtx, $ipHash, $emailAddr);

        if (!$sentToOwner) {
            return [
                'action' => 'rerender',
                'errors' => [],
                'values' => $values,
                'global_error' => 'mail_failed',
            ];
        }

        if ($emailAddr !== '') {
            // Best-effort; failure of the confirmation does not fail the submit.
            $this->mailer->sendSenderConfirmation($mailCtx, $ipHash, $emailAddr);
        }

        return ['action' => 'redirect', 'url' => $successUrl];
    }

    /** HMAC-hash for the IP so the mail log never contains plaintext. */
    private function hashIp(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, $this->secret), 0, 16);
    }

    /**
     * Pull only string-shaped fields out of $_POST so a re-render of the
     * form doesn't echo unexpected types. Hard-cap length per field.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function keepValues(array $post): array
    {
        $reserved = ['_token', '_captcha_token', '_captcha_nonce', $this->honeypotField];
        $out = [];
        foreach ($post as $k => $v) {
            if (is_string($k) && is_string($v) && !in_array($k, $reserved, true)) {
                $out[$k] = mb_substr($v, 0, 5000, 'UTF-8');
            }
        }
        return $out;
    }

    /**
     * Static factory wiring everything from config + secret. Front
     * controllers call this once.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(Engine $engine, array $config, string $stateDir): self
    {
        $secret = Secret::load($stateDir);

        $rateLimiter = new RateLimiter(
            $stateDir,
            $secret,
            (int)($config['rate_limit']['window_seconds'] ?? 600),
            (int)($config['rate_limit']['max_per_window'] ?? 5),
        );
        $blocklist = new Blocklist(
            $stateDir,
            $secret,
            (int)($config['blocklist']['fail_threshold'] ?? 3),
            (int)($config['blocklist']['fail_window'] ?? 1800),
            (int)($config['blocklist']['block_duration'] ?? 1800),
        );
        $validator = new Validator(
            (array)($config['contact']['fields'] ?? [])
        );
        $mailLog = new MailLog(
            $stateDir,
            (bool)($config['mail']['log_enabled'] ?? true),
            (int)($config['mail']['log_retention_days'] ?? 30),
            (bool)($config['mail']['log_include_body'] ?? false),
        );
        $mailer = new Mailer(
            $engine,
            new PhpMailTransport(),
            $mailLog,
            $stateDir,
            (array)($config['mail'] ?? []),
        );
        $captchaStore = new CaptchaStore(
            $stateDir,
            (int)($config['captcha']['max_age'] ?? 600),
        );
        $captchaMissTracker = new CaptchaMissTracker(
            $stateDir,
            $secret,
            (int)($config['captcha']['miss_window'] ?? 1800),
            (int)($config['captcha']['miss_threshold'] ?? 3),
        );

        // Honeypot field name: optional config override, otherwise
        // derived per-installation from the secret (recommended).
        $honeypotField = Honeypot::resolveFieldName(
            (array)($config['contact'] ?? []),
            $secret
        );

        return new self(
            $engine,
            $rateLimiter,
            $blocklist,
            $validator,
            $mailer,
            $captchaStore,
            $captchaMissTracker,
            $secret,
            $honeypotField,
            (int)($config['csrf']['min_age_seconds'] ?? 3),
            (int)($config['csrf']['max_age_seconds'] ?? 3600),
            (bool)($config['captcha']['enabled'] ?? false),
            (int)($config['captcha']['max_age'] ?? 600),
        );
    }
}
