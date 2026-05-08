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
 * Step 2 of the login flow: 6-digit code from mail.
 *
 * Requires a `pre-otp`-stage session cookie issued by LoginController.
 * On a wrong/expired code, the session stays in `pre-otp` and the
 * operator can retry until either the OTP attempt-cap or the per-IP
 * rate limit kicks in.
 *
 * On success:
 *   - The session is rotated (anti-fixation): the pre-otp id is
 *     destroyed, a fresh `authed` id is issued.
 *   - The OTP file is deleted (single-use).
 *   - Operator is redirected to /.
 */
final class OtpController
{
    private const FORM_ID = 'otp';

    public function __construct(
        private OtpStore $otps,
        private Session $sessions,
        private RateLimiter $rateLimiter,
        private Csrf $csrf,
        private Renderer $renderer,
        private AuditLog $audit,
        private CookieJar $cookies,
        private string $cookieName,
        private int $authedSessionMaxAge,
    ) {
    }

    public function showForm(Request $req, string $cookieValue): Response
    {
        $session = $this->sessions->load($cookieValue, $req->clientIp(), $req->userAgent());
        if ($session === null || $session['stage'] !== 'pre-otp') {
            return Response::redirect($req->url('login'));
        }
        return Response::html($this->renderer->page('otp', [
            'BASE'   => $req->basePath(),
            'CSRF'   => $this->csrf->issue(self::FORM_ID),
            'ERROR'  => '',
        ]));
    }

    public function submit(Request $req, string $cookieValue): Response
    {
        if (!$this->rateLimiter->hit($req->clientIp())) {
            $this->audit->record('login.otp.ratelimit', $req->clientIp());
            return $this->renderError($req, 'Too many attempts. Please try again later.', 429);
        }

        $session = $this->sessions->load($cookieValue, $req->clientIp(), $req->userAgent());
        if ($session === null || $session['stage'] !== 'pre-otp') {
            return Response::redirect($req->url('login'));
        }

        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            $this->audit->record('login.otp.csrf.invalid', $req->clientIp(), $session['user']);
            return $this->renderError($req, 'Form expired. Please reload and try again.', 400);
        }

        $code = trim((string)$req->post('code', ''));
        if (preg_match('/^[0-9]{4,10}$/', $code) !== 1) {
            $this->audit->record('login.otp.fail', $req->clientIp(), $session['user'], ['reason' => 'shape']);
            return $this->renderError($req, 'Invalid code.', 401);
        }

        $ok = $this->otps->verify($session['user'], $code);
        if (!$ok) {
            $this->audit->record('login.otp.fail', $req->clientIp(), $session['user']);
            return $this->renderError($req, 'Invalid code.', 401);
        }

        $newCookie = $this->sessions->upgradeToAuthed($cookieValue, $req->clientIp(), $req->userAgent());
        $this->audit->record('login.otp.ok', $req->clientIp(), $session['user']);

        $response = Response::redirect($req->url(''));
        return $this->cookies->attach($response, $this->cookieName, $newCookie, $this->authedSessionMaxAge);
    }

    private function renderError(Request $req, string $error, int $status): Response
    {
        return Response::html($this->renderer->page('otp', [
            'BASE'   => $req->basePath(),
            'CSRF'   => $this->csrf->issue(self::FORM_ID),
            'ERROR'  => $error,
        ]), $status);
    }
}
