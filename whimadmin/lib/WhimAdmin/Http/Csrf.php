<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Http;

use H42\WhimCMS\Security\Form\Csrf as CoreCsrf;

/**
 * Form-scoped CSRF tokens for WhimAdmin.
 *
 * Thin wrapper around the WhimCMS core's Csrf primitive, configuring
 * sensible admin-side defaults (timing window, bind strategy) and
 * isolating WhimAdmin's tokens from the public site's via a hard
 * `whimadmin:` formId prefix — a token issued for the public contact
 * form can never be replayed against an admin POST and vice versa.
 *
 * formId convention:
 *   whimadmin:setup
 *   whimadmin:login
 *   whimadmin:otp
 *   whimadmin:logout
 *   whimadmin:page-save     (Phase 2+)
 *   whimadmin:asset-upload  (Phase 6)
 *
 * Bind strategy is fixed to 'ip_ua' (strictest) — admin sessions
 * don't roam across networks the way mobile visitors do.
 */
final class Csrf
{
    private const FORM_ID_PREFIX  = 'whimadmin:';
    private const MIN_AGE_SECONDS = 0;       // accept submissions even if instantaneous

    /**
     * Token lifetime. Matches the default session-absolute TTL so a
     * page edit form opened, left for an hour, and saved still has a
     * valid token. Below this would force a "Form expired" reload
     * during long editing sessions — annoying without security gain
     * because the session itself is the limiting authority.
     */
    private const MAX_AGE_SECONDS = 28800;   // 8 h — matches session.absolute_seconds

    public function __construct(
        private string $secret,
        private string $clientIp,
        private string $userAgent,
    ) {
    }

    public function issue(string $formId): string
    {
        return CoreCsrf::issue(
            $this->secret,
            $this->bindKey(),
            $this->scopedFormId($formId),
        );
    }

    public function validate(string $token, string $formId): bool
    {
        if ($token === '') {
            return false;
        }
        return CoreCsrf::validate(
            token:          $token,
            secret:         $this->secret,
            bindKey:        $this->bindKey(),
            formId:         $this->scopedFormId($formId),
            minAgeSeconds:  self::MIN_AGE_SECONDS,
            maxAgeSeconds:  self::MAX_AGE_SECONDS,
        );
    }

    /**
     * Convenience for controllers: returns true if a request's POST
     * body carries a valid token under the expected field name.
     */
    public function validateFromRequest(Request $req, string $formId, string $fieldName = '_csrf'): bool
    {
        return $this->validate((string)$req->post($fieldName, ''), $formId);
    }

    private function scopedFormId(string $raw): string
    {
        if ($raw === '' || preg_match('/^[a-z][a-z0-9-]{0,40}$/', $raw) !== 1) {
            throw new \InvalidArgumentException("Bad CSRF formId: {$raw}");
        }
        return self::FORM_ID_PREFIX . $raw;
    }

    private function bindKey(): string
    {
        return CoreCsrf::deriveBindKey($this->clientIp, $this->userAgent, 'ip_ua');
    }
}
