<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\CookieJar;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;

/**
 * POST /logout — destroy the session and clear the cookie.
 *
 * Requires CSRF (formId 'logout') so a third-party site cannot
 * forcefully log the operator out via a cross-site POST. Idempotent:
 * a second call with no session is a no-op redirect.
 */
final class LogoutController
{
    private const FORM_ID = 'logout';

    public function __construct(
        private Session $sessions,
        private Csrf $csrf,
        private AuditLog $audit,
        private CookieJar $cookies,
        private string $cookieName,
    ) {
    }

    public function submit(Request $req, string $cookieValue, ?string $username): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            $this->audit->record('logout.csrf.invalid', $req->clientIp(), $username);
            return Response::redirect($req->url('login'));
        }

        if ($cookieValue !== '') {
            $this->sessions->destroy($cookieValue);
        }
        $this->audit->record('logout', $req->clientIp(), $username);

        $response = Response::redirect($req->url('login'));
        return $this->cookies->clear($response, $this->cookieName);
    }
}
