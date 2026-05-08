<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Auth;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\View\Renderer;

/**
 * First-run dispatcher: handles every request while no admin user
 * exists yet.
 *
 * Behaviour:
 *
 *   - On entry, ensure a setup token is present. If we just minted
 *     one, log it via PHP's `error_log()` so the operator can read
 *     it from the host's error-log file. The token is NEVER shown
 *     in the HTTP response — the only retrieval channel is the
 *     server-side log.
 *
 *   - GET /setup?token=…   → delegate to SetupController::showForm
 *   - POST /setup          → delegate to SetupController::submit
 *   - everything else      → render the "Setup required" page (503)
 *
 * Once the user record exists, the Kernel skips this dispatcher
 * entirely and `/setup` 404s.
 */
final class FirstRunController
{
    public function __construct(
        private SetupTokenStore $tokens,
        private SetupController $setup,
        private Renderer $renderer,
        private AuditLog $audit,
        private int $tokenTtlSeconds,
    ) {
    }

    /**
     * Entry point — returns the Response for any first-run request.
     */
    public function dispatch(Request $req): Response
    {
        $this->maybeIssueToken($req);

        $path   = $req->path();
        $method = $req->method();

        if ($path === 'setup' && $method === 'GET') {
            return $this->setup->showForm($req);
        }
        if ($path === 'setup' && $method === 'POST') {
            return $this->setup->submit($req);
        }
        return $this->renderSetupRequired($req);
    }

    private function maybeIssueToken(Request $req): void
    {
        $newToken = $this->tokens->ensureIssued();
        if ($newToken === null) {
            return;
        }
        // The plaintext token has been written to a sidecar file in
        // whimadmin/var/state/ by SetupTokenStore::writeNewToken. We
        // log only the LOCATION here — the host's error log might be
        // readable by other tenants on shared hosting and is often
        // shipped to centralised log aggregators, so the token itself
        // does not belong there. Operator retrieves it via SSH/SFTP.
        \error_log(sprintf(
            '[WhimAdmin] First-run setup token written to %s (valid for %d hour(s)).',
            $this->tokens->plaintextPath(),
            (int)($this->tokenTtlSeconds / 3600),
        ));
        $this->audit->record('setup.token.generate', $req->clientIp());
    }

    private function renderSetupRequired(Request $req): Response
    {
        $body = $this->renderer->page('setup-required', [
            'BASE' => $req->basePath(),
        ]);
        return Response::html($body, 503);
    }
}
