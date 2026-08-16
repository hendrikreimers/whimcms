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
 *   2. Validate the CSRF/timing token. Failure → reject, NO strike —
 *      the one gate that deliberately does not punish; step 2 in the
 *      body says why.
 *   3. Rate-limit. Failure → reject (no strike). `rate_limit` in
 *      config/security.php: 20 per 10 min as shipped, 5 per 10 min as
 *      the code fallback when the key is absent.
 *   4. Inspect honeypot. Filled → strike + pretend success (for bots).
 *   5. Proof-of-work captcha — missing (throttled via
 *      CaptchaMissTracker), invalid, or replayed → strike + reject.
 *      THREE of the four strike sites live in this step.
 *   6. Run field validation. Errors → return rerender with errors.
 *   7. Build mail context, send recipient mail (plus sender
 *      confirmation if enabled), redirect to /<lang>/…?sent=1#contact.
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
        //
        // NO Blocklist strike here, deliberately — do not "restore" it.
        // This is the only gate reachable WITHOUT a valid token, which
        // made it the one an outsider could aim at a third party: six
        // silent POSTs — from a foreign page via the visitor's browser,
        // or by anyone sharing the visitor's egress address (CGNAT,
        // VPN, Tor exit) — bought that visitor a 15-minute block.
        //
        // It also punished the innocent majority. A failed token means
        // expired (max_age 7200 s), submitted under min_age, or a
        // changed IP — and with bind_strategy 'ip_ua' the token binds
        // the FULL address while the strike counts on the /64, so one
        // roaming mobile visitor burnt strikes for their whole
        // connection. contact-form.js re-arms and retries once per
        // click, i.e. up to two strikes per click. The client-side
        // design already treats this as a recoverable, ordinary event;
        // the strike treated it as an attack.
        //
        // Striking here bought nothing in return. A blocked client takes
        // the SAME response path as a rejected one (see isBlocked above):
        // a full page re-render on the form-encoded path, a 422 JSON body
        // on the fetch path. The block never made the answer cheaper —
        // it swapped the echoed input for an empty one and the error
        // code for 'blocked'. No mail and
        // no state write hung on it either, and the rate limiter below is
        // never reached on this path anyway. The four strike sites
        // further down all sit behind a PASSED token check — they
        // identify a client that held a token issued to itself — and
        // they stay.
        $token = is_string($post['_token'] ?? null) ? (string)$post['_token'] : '';
        if (!Csrf::validate($token, $this->secret, $bindKey, self::FORM_ID, $this->csrfMinAge, $this->csrfMaxAge)) {
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

        // 4. Honeypot — bots fill it, humans don't see it.
        // Coerce before the emptiness test. On the JSON body path the
        // client controls the value's *type* (json_decode leaves can be
        // arrays / numbers / bools), so a filled honeypot sent as a
        // non-string — e.g. {"<field>": ["x"]} — must still trip. An
        // `is_string` guard alone would let it slip past and dodge the
        // strike. Arrays count as filled when non-empty; every scalar is
        // stringified and trimmed.
        $honey = $post[$this->honeypotField] ?? '';
        $honeyFilled = is_array($honey) ? $honey !== [] : trim((string)$honey) !== '';
        if ($honeyFilled) {
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
            // consumed. Positive guard on purpose — EVERY non-CONSUMED
            // verdict refuses, so a state added to CaptchaStore later
            // cannot fall through as "passed". Only a genuine replay is
            // also punished: when the store simply could not write
            // (quota, read-only mount, fd limit) the fault is ours, and
            // striking the visitor for it is the posture the sibling
            // CaptchaMissTracker explicitly rejects. The store has
            // already logged path and errno in that case.
            $captchaState = $this->captchaStore->consume($cToken, $cNonce);
            if ($captchaState !== CaptchaStore::CONSUMED) {
                if ($captchaState === CaptchaStore::REPLAY) {
                    $this->blocklist->strike($clientIp);
                    Log::info('Contact: captcha replay', []);
                }
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

    /**
     * HMAC-hash for the IP so the mail log never contains plaintext.
     *
     * **Deliberately not `Security\ThrottleKey::derive()`**, and this is
     * not an oversight to be tidied away: that helper reduces an IPv6
     * client to its /64 network, which is right for a counter and wrong
     * here. A throttle asks "which connection is this?", an audit entry
     * asks "which device was this?" — the mail log exists to answer the
     * second question, so it keeps the full address.
     *
     * The 16 hex characters (vs. 32 in the throttling stores) are only a
     * size cap on the log file; they are the prefix of the same digest,
     * not a different scheme.
     */
    private function hashIp(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, $this->secret), 0, 16);
    }

    /**
     * Values echoed back into the form when a submission is rejected
     * BEFORE the validator runs (bad token, rate limit, captcha), so the
     * visitor doesn't lose what they typed. Hard-cap length per field.
     *
     * Driven by the schema, not by the request: only fields the
     * validator knows are kept, everything else a client sent is
     * dropped. `_token`, `_captcha_token`, `_captcha_nonce` and the
     * honeypot need no explicit exclusion — they are not form fields, so
     * they are not in the schema.
     *
     * Until 2026-08-13 this kept EVERY string key in $_POST. That was
     * harmless only because the template addresses fields by name; a
     * later loop over FORM_VALUES would have turned it into a
     * reflection surface for arbitrary attacker-supplied keys. It also
     * disagreed with the validation path, which has always returned
     * exactly the configured fields (Validator::validate) — the two
     * re-render paths now produce the same shape.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function keepValues(array $post): array
    {
        $out = [];
        foreach ($this->validator->fieldNames() as $field) {
            $v = $post[$field] ?? null;
            if (is_string($v)) {
                $out[$field] = mb_substr($v, 0, 5000, 'UTF-8');
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
            (int)($config['rate_limit']['max_per_window'] ?? 20),
        );
        $blocklist = new Blocklist(
            $stateDir,
            $secret,
            (int)($config['blocklist']['fail_threshold'] ?? 6),
            (int)($config['blocklist']['fail_window'] ?? 1800),
            (int)($config['blocklist']['block_duration'] ?? 900),
        );
        $validator = new Validator(
            (array)($config['contact']['fields'] ?? [])
        );
        // Retention (mail.log_retention_days) is no longer a MailLog
        // concern — the Kernel wires a Maintenance\DayDirSweeper for it.
        $mailLog = new MailLog(
            $stateDir,
            (bool)($config['mail']['log_enabled'] ?? true),
            (bool)($config['mail']['log_include_body'] ?? false),
        );
        $mailer = new Mailer(
            $engine,
            new PhpMailTransport(),
            $mailLog,
            $stateDir,
            (array)($config['mail'] ?? []),
        );
        // captcha.max_age now only parameterises the TtlFileSweeper the
        // Kernel wires for captcha-used/ — the store itself no longer prunes.
        $captchaStore = new CaptchaStore($stateDir);
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
            (int)($config['csrf']['max_age_seconds'] ?? 7200),
            (bool)($config['captcha']['enabled'] ?? true),
            (int)($config['captcha']['max_age'] ?? 7200),
        );
    }
}
