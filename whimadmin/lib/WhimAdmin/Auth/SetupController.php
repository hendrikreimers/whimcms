<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\View\Renderer;

/**
 * One-shot setup flow: turns a fresh deploy into a working install
 * by creating the single admin user record.
 *
 * Gating:
 *   - Reachable only while NO user record exists. After the user is
 *     created, /setup 404s.
 *   - Requires a valid setup token presented as `?token=...` on GET
 *     and as a hidden form field on POST. The token was generated
 *     at boot when no user existed yet, written to the host's PHP
 *     error_log, and stored HMAC-only on disk.
 *   - POST additionally requires a valid CSRF token (formId 'setup').
 *
 * After a successful POST:
 *   - User record is written (atomic, 0o600).
 *   - Setup-token file is deleted (single-use).
 *   - Operator is redirected to /login.
 */
final class SetupController
{
    private const FORM_ID = 'setup';

    public function __construct(
        private UserStore $users,
        private SetupTokenStore $tokens,
        private Csrf $csrf,
        private Renderer $renderer,
        private AuditLog $audit,
    ) {
    }

    public function showForm(Request $req): Response
    {
        if ($this->users->exists()) {
            return $this->notFound();
        }
        $token = (string)$req->query('token', '');
        if (!$this->tokens->isValid($token)) {
            $this->audit->record('setup.token.invalid', $req->clientIp());
            return $this->notFound();
        }

        return Response::html($this->renderer->page('setup', [
            'BASE'      => $req->basePath(),
            'CSRF'      => $this->csrf->issue(self::FORM_ID),
            'TOKEN'     => $token,
            'ERROR'     => '',
            'USERNAME'  => '',
            'EMAIL'     => '',
            'PWD_MIN'   => UserStore::PASSWORD_MIN,
            'PWD_MAX'   => UserStore::PASSWORD_MAX,
        ]));
    }

    public function submit(Request $req): Response
    {
        if ($this->users->exists()) {
            return $this->notFound();
        }
        $token = (string)$req->post('token', '');
        if (!$this->tokens->isValid($token)) {
            $this->audit->record('setup.token.invalid', $req->clientIp());
            return $this->notFound();
        }
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            $this->audit->record('setup.csrf.invalid', $req->clientIp());
            // Re-issue token so the form can resubmit cleanly.
            return $this->renderError($req, $token, 'Form expired. Please try again.', '', '');
        }

        $username     = trim((string)$req->post('username', ''));
        $email        = trim((string)$req->post('email', ''));
        $password     = (string)$req->post('password', '');
        $passwordConf = (string)$req->post('password_confirm', '');

        // Validate
        if (preg_match(UserStore::USERNAME_PATTERN, $username) !== 1) {
            return $this->renderError($req, $token, 'Username must be 3–32 chars, start with a letter, [A–Z, a–z, 0–9, _, -] only.', $username, $email);
        }
        if (!UserStore::isValidEmail($email)) {
            return $this->renderError($req, $token, 'Email is not valid.', $username, $email);
        }
        $pwdError = UserStore::passwordPolicyError($password);
        if ($pwdError !== null) {
            return $this->renderError($req, $token, $pwdError, $username, $email);
        }
        if (!hash_equals($password, $passwordConf)) {
            return $this->renderError($req, $token, 'Password confirmation does not match.', $username, $email);
        }

        try {
            $this->users->create($username, $email, $password);
        } catch (\Throwable $e) {
            $this->audit->record('setup.create.fail', $req->clientIp(), $username);
            return $this->renderError($req, $token, 'Setup failed. Please retry.', $username, $email);
        }

        $this->tokens->consume();
        $this->audit->record('setup.token.consume', $req->clientIp(), $username);

        return Response::redirect($req->url('login'));
    }

    private function renderError(Request $req, string $token, string $error, string $username, string $email): Response
    {
        return Response::html($this->renderer->page('setup', [
            'BASE'      => $req->basePath(),
            'CSRF'      => $this->csrf->issue(self::FORM_ID),
            'TOKEN'     => $token,
            'ERROR'     => $error,
            'USERNAME'  => $username,
            'EMAIL'     => $email,
            'PWD_MIN'   => UserStore::PASSWORD_MIN,
            'PWD_MAX'   => UserStore::PASSWORD_MAX,
        ]), 400);
    }

    private function notFound(): Response
    {
        return Response::plain('Not found.', 404);
    }
}
