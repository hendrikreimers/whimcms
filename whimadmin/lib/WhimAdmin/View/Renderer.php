<?php
declare(strict_types=1);

namespace H42\WhimAdmin\View;

use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimCMS\Template\Engine;

/**
 * Render whimadmin's HTML views via the WhimCMS core template engine.
 *
 * The core engine is consumed read-only — same lib code, separate
 * Engine instance pointed at `whimadmin/views/` so admin templates and
 * site templates do not share the compiled-token cache or annotation
 * registry. Block partials don't exist in `whimadmin/views/` so the
 * core's annotation scan finds nothing and proceeds — no admin page
 * uses the `{% blocks %}` directive.
 *
 * Exposes a single `render($name, $context)` plus a small helper
 * `flash()` for one-shot message rendering. The Kernel constructs
 * one Renderer per request; controllers receive it via the action
 * dispatch.
 */
final class Renderer
{
    private Engine $engine;

    public function __construct(string $viewsDir)
    {
        // The core Engine validates the directory in its constructor.
        // No varDir / rootDir — admin views never invoke {% image %},
        // so passing empty strings is safe (the directive would throw
        // on render if called, which we don't).
        $this->engine = new Engine($viewsDir);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        // Hard-cap template names to the same shape as core. The
        // engine itself rejects bad names — this is defence in depth
        // so a controller-side bug can't smuggle a path traversal.
        if (preg_match('#^[a-zA-Z0-9_/-]+$#', $template) !== 1) {
            throw new \InvalidArgumentException("Bad template name: {$template}");
        }
        return $this->engine->render($template, $context);
    }

    /**
     * Plain-text render — for mail bodies. Preserves user-visible
     * bytes verbatim (no htmlspecialchars).
     *
     * @param array<string, mixed> $context
     */
    public function renderText(string $template, array $context = []): string
    {
        if (preg_match('#^[a-zA-Z0-9_/-]+$#', $template) !== 1) {
            throw new \InvalidArgumentException("Bad template name: {$template}");
        }
        return $this->engine->renderText($template, $context);
    }

    /**
     * Compose a full page: render the inner template, then embed it
     * inside the layout via the `CONTENT` context key.
     *
     * The layout template uses `{% html: CONTENT %}` to insert the
     * inner HTML verbatim. This is safe because:
     *   - CONTENT is produced by THIS renderer in this same call —
     *     never reaches it from user input
     *   - the inner template went through normal Engine escaping for
     *     any user-supplied values it referenced
     *
     * The core Engine doesn't have a template-inheritance directive,
     * so this two-pass render is the cheapest way to share a chrome
     * (header/footer) across admin pages without inlining it
     * everywhere.
     *
     * @param array<string, mixed> $context
     */
    public function page(string $innerTemplate, array $context = [], string $layout = 'layout'): string
    {
        $inner = $this->render($innerTemplate, $context);
        $context['CONTENT'] = $inner;
        return $this->render($layout, $context);
    }

    /**
     * Build the keys every authed admin view needs (basePath, signed-in
     * username for the header, logout-form CSRF token, site root for
     * asset URLs). Controllers spread the result into their per-page
     * context dict — DRYs the boilerplate that used to live in 6+
     * separate render paths.
     *
     * Pass $username = '' for pre-auth views (login/setup/otp); the
     * layout uses the empty value as a falsy signal to hide the nav
     * and logout pill.
     *
     * @return array<string, mixed>
     */
    public static function commonContext(Request $req, string $username, ?Csrf $csrf = null): array
    {
        return [
            'BASE'        => $req->basePath(),
            'SITE_ROOT'   => $req->siteRoot(),
            'AUTHED_USER' => $username,
            'CSRF_LOGOUT' => ($username !== '' && $csrf !== null) ? $csrf->issue('logout') : '',
        ];
    }
}
