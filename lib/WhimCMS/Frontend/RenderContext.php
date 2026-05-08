<?php
declare(strict_types=1);

namespace H42\WhimCMS\Frontend;

use H42\WhimCMS\Security\Form\Captcha\Captcha;
use H42\WhimCMS\Config;
use H42\WhimCMS\Security\Form\Csrf;
use H42\WhimCMS\Security\EmailProtection;
use H42\WhimCMS\Security\Form\Honeypot;
use H42\WhimCMS\Router;
use H42\WhimCMS\Security\Secret;
use H42\WhimCMS\Seo\PageSeo;

/**
 * Builds the root render-context array consumed by the layout template.
 *
 * Pulled out of the front controller so the same shape can be produced
 * for normal pages, the 404 page, and the contact-form POST flow without
 * duplicating the wiring.
 */
final class RenderContext
{
    /**
     * @param array<string, mixed>                  $dict
     * @param array<string, mixed>                  $meta
     * @param array<int, string>                    $supportedLangs
     * @param array<string, array<string, string>>  $routes
     * @param list<\H42\WhimCMS\Content\Block>|null         $blocks
     * @param array<string, mixed>|null             $formState
     * @return array<string, mixed>
     */
    public static function build(
        array $dict,
        string $lang,
        string $slug,
        string $basePath,
        array $meta,
        string $pageTemplate,
        array $supportedLangs,
        array $routes,
        bool $singleLang,
        string $stateDir,
        ?array $blocks = null,
        ?array $formState = null,
        bool $formSent = false,
        string $csrfBindKey = '',
        string $themeUrl = '',
        string $csrfFormId = 'contact',
    ): array {
        $globals    = (array)Config::get('globals', []);
        $langSwitch = Router::buildLangSwitch($slug, $lang, $supportedLangs, $routes, $basePath);
        $langRoutes = $routes[$lang] ?? [];
        $urls       = self::computeUrls($langRoutes, $lang, $basePath, $singleLang);

        // Issue a fresh CSRF/timing token for any form on the page. Even
        // pages without a form get one — cost is negligible and keeps
        // the context shape uniform. The bind key ties the token to the
        // current client (IP + UA); the formId scopes it to a specific
        // form so a token issued for `contact` cannot be replayed at a
        // future booking / newsletter / etc. endpoint. The formId string
        // here MUST match what the corresponding controller passes to
        // Csrf::validate — see ContactController::FORM_ID for the current
        // single endpoint.
        $secret    = Secret::load($stateDir);
        $formToken = Csrf::issue($secret, $csrfBindKey, $csrfFormId);

        // Captcha challenge (proof-of-work). Always issued so the form
        // template can render the hidden inputs without conditional
        // shape changes; enforcement is gated by config in the controller.
        $captchaCfg = (array)Config::get('captcha', []);
        $captchaEnabled = (bool)($captchaCfg['enabled'] ?? false);
        $captcha = Captcha::issue(
            $secret,
            max(0, (int)($captchaCfg['difficulty'] ?? 16))
        );
        $captcha['enabled'] = $captchaEnabled;

        $currentPageUrl = $urls[$slug] ?? Router::canonicalUrl($slug, $lang, $langRoutes, $basePath, $singleLang);
        $seo = PageSeo::build($slug, $lang, $supportedLangs, $routes, $basePath, $singleLang, $meta);

        // Email-protection — config-driven obfuscation of any address
        // exposed on the page. EMAIL.<key> ends up as a struct the
        // template can render either as a real mailto or as a hydratable
        // span depending on the `protected` flag.
        $emailCfg = (array)Config::get('email_protection', []);
        $emails = EmailProtection::buildContext(
            $dict,
            (array)($emailCfg['paths'] ?? []),
            (string)($emailCfg['format'] ?? '%user%@%domain%'),
            (bool)($emailCfg['enabled'] ?? false)
        );

        // Honeypot field name — derived from the secret so the form's
        // `name` attribute matches what the controller will read out of
        // $_POST. Templates emit it via {{ HONEYPOT_FIELD }}; never
        // hard-code "website" or any other literal here.
        $honeypotField = Honeypot::resolveFieldName(
            (array)Config::get('contact', []),
            $secret
        );

        return array_merge($globals, [
            'CURRENT_LANG'      => $dict,
            'LANG'              => $lang,
            'LANGS'             => $supportedLangs,
            'PAGE'              => $slug,
            'BASE'              => $basePath,
            'META'              => $meta,
            'PAGE_TEMPLATE'     => $pageTemplate,
            'BLOCKS'            => $blocks,
            'MULTI_LANG'        => !$singleLang,
            'LANG_SWITCH'       => $langSwitch,
            'URLS'              => $urls,
            'CURRENT_PAGE_URL'  => $currentPageUrl,
            'SEO'               => $seo,
            'EMAIL'             => $emails,
            'CAPTCHA'           => $captcha,
            'CONTACT_ENABLED'   => (bool)Config::get('contact.enabled', true),
            'THEME_URL'         => $themeUrl,
            'FORM_TOKEN'        => $formToken,
            'FORM_SENT'         => $formSent,
            'FORM_ERRORS'       => is_array($formState['errors'] ?? null) ? $formState['errors'] : [],
            'FORM_VALUES'       => is_array($formState['values'] ?? null) ? $formState['values'] : [],
            'FORM_GLOBAL_ERROR' => is_string($formState['global_error'] ?? null) ? $formState['global_error'] : '',
            'HONEYPOT_FIELD'    => $honeypotField,
        ]);
    }

    /**
     * Slug → URL map for the active language.
     *
     * @param array<string, string> $langRoutes
     * @return array<string, string>
     */
    public static function computeUrls(array $langRoutes, string $lang, string $basePath, bool $singleLang): array
    {
        $urls = [];
        foreach ($langRoutes as $pageSlug) {
            if (!isset($urls[$pageSlug])) {
                $urls[$pageSlug] = Router::canonicalUrl($pageSlug, $lang, $langRoutes, $basePath, $singleLang);
            }
        }
        return $urls;
    }
}
