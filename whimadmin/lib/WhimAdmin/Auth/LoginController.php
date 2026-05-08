<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\CookieJar;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\View\Renderer;
use H42\WhimCMS\Security\RateLimiter;

/**
 * Step 1 of the login flow: username + password.
 *
 * On successful credential check:
 *   1. Issue a `pre-otp`-stage session.
 *   2. Generate a fresh OTP via OtpStore::issue.
 *   3. Mail the code via OtpMailer.
 *   4. Redirect to /otp.
 *
 * On any failure (rate-limit hit, CSRF invalid, credentials wrong,
 * mail send failed) the response is a uniform 401-equivalent
 * re-render with a generic "Invalid credentials." error — no path
 * for user enumeration, no signal of "user exists but password
 * wrong vs user doesn't exist".
 *
 * The rate limit applies per-IP across the login flow (login attempts
 * + OTP attempts share the same bucket through OtpController). 5
 * attempts per 5 min by default.
 */
final class LoginController
{
    private const FORM_ID = 'login';

    /**
     * @param array{ttl_seconds:int, digits:int, max_attempts:int} $otpConfig
     */
    public function __construct(
        private UserStore $users,
        private OtpStore $otps,
        private OtpMailer $otpMailer,
        private Session $sessions,
        private RateLimiter $rateLimiter,
        private Csrf $csrf,
        private Renderer $renderer,
        private AuditLog $audit,
        private CookieJar $cookies,
        private string $cookieName,
        private array $otpConfig,
    ) {
    }

    public function showForm(Request $req): Response
    {
        return Response::html($this->renderer->page('login', [
            'BASE'     => $req->basePath(),
            'CSRF'     => $this->csrf->issue(self::FORM_ID),
            'ERROR'    => '',
            'USERNAME' => '',
        ]));
    }

    public function submit(Request $req): Response
    {
        // Rate limit FIRST — even before CSRF check, so a flood of
        // bad-CSRF attempts cannot bypass the throttle.
        if (!$this->rateLimiter->hit($req->clientIp())) {
            $this->audit->record('login.ratelimit', $req->clientIp());
            return $this->renderError($req, '', 'Too many attempts. Please try again in a few minutes.', 429);
        }

        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            $this->audit->record('login.csrf.invalid', $req->clientIp());
            return $this->renderError($req, '', 'Form expired. Please reload and try again.', 400);
        }

        $username = trim((string)$req->post('username', ''));
        $password = (string)$req->post('password', '');

        // Cheap shape validation. Not for security (verify() is timing-
        // equal anyway) — just to short-circuit obvious garbage without
        // burning argon2id cost.
        if ($username === '' || $password === '') {
            $this->audit->record('login.password.fail', $req->clientIp(), $username, ['reason' => 'empty']);
            return $this->renderError($req, $username, 'Invalid credentials.', 401);
        }

        $record = $this->users->verify($username, $password);
        if ($record === null) {
            $this->audit->record('login.password.fail', $req->clientIp(), $username);
            return $this->renderError($req, $username, 'Invalid credentials.', 401);
        }

        $this->audit->record('login.password.ok', $req->clientIp(), $username);

        // Issue OTP, mail it, set pre-otp session.
        $code = $this->otps->issue(
            username:     $username,
            digits:       $this->otpConfig['digits'],
            ttlSeconds:   $this->otpConfig['ttl_seconds'],
            maxAttempts:  $this->otpConfig['max_attempts'],
        );
        $sent = $this->otpMailer->send($username, $record['email'], $code, $req->clientIp());

        if (!$sent) {
            $this->otps->clear($username);
            return $this->renderError($req, $username, 'Could not deliver login code. Try again later.', 503);
        }

        $cookieValue = $this->sessions->issue($username, 'pre-otp', $req->clientIp(), $req->userAgent());
        $response    = Response::redirect($req->url('otp'));
        return $this->cookies->attach($response, $this->cookieName, $cookieValue, $this->otpConfig['ttl_seconds']);
    }

    private function renderError(Request $req, string $username, string $error, int $status): Response
    {
        return Response::html($this->renderer->page('login', [
            'BASE'     => $req->basePath(),
            'CSRF'     => $this->csrf->issue(self::FORM_ID),
            'ERROR'    => $error,
            'USERNAME' => $username,
        ]), $status);
    }
}
