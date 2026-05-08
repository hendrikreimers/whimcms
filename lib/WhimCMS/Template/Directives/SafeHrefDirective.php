<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Content\HrefSanitizer;
use H42\WhimCMS\Log;
use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `{% safe_href: <expr> %}` — evaluate an expression to a URL string,
 * validate it against the shared HrefSanitizer allowlist, and emit it
 * HTML-escaped for use inside an `href=` / `src=` attribute.
 *
 * Use this in templates wherever an href value comes from author-
 * controlled content (block attributes from `.md` files, i18n strings
 * the operator can edit via the admin, or anything else that isn't a
 * compile-time constant from config). Plain `{{ x }}` HTML-escapes the
 * output but does NOT block dangerous schemes — `javascript:alert(1)`
 * survives `{{ }}` because the escape pass only handles `< > & " '`,
 * not URL semantics.
 *
 * Allowed schemes (delegated to HrefSanitizer):
 *
 *   - https://       (no `@` in authority — credential-form rejected)
 *   - mailto: / tel:
 *   - /...           root-relative (no scheme-relative `//host`)
 *   - #...           in-page anchor
 *
 * Anything else — including http://, javascript:, data:, vbscript:,
 * file:, scheme-relative //host, URL-encoded scheme variants
 * (javascript%3A...), HTML-entity variants (javascript&#58;...) —
 * emits an empty string and logs a warning. Empty `href=""` is the
 * deliberate fail-safe default:
 *
 *   - Same-page reload on click instead of executing attacker JS.
 *   - Visible-broken in dev tools so the operator notices.
 *
 * The directive does NOT throw on a bad href — one invalid URL in a
 * single block must not 500 the whole page. Visibility is via the log
 * (`var/logs/`), which the operator reviews for stored-XSS attempts.
 *
 * Output is HTML-attribute-safe: the result of HrefSanitizer::check()
 * may contain `&`, `?`, `=`, `'`, etc. (legitimate URL chars) which
 * would break the surrounding `href="..."` attribute if not escaped.
 * The directive runs the result through `Sanitizer::escape()` before
 * returning.
 *
 * Text-mode (Engine::renderText) is NOT special-cased: a hand-written
 * plain-text mail template would use `{{ url }}` directly, not
 * `{% safe_href %}` — the directive's purpose is HTML-attribute safety,
 * which has no analogue in plain text. If a future text-mode template
 * does call safe_href, it gets HTML-escaped output (cosmetic `&amp;`
 * instead of `&`); not pretty, but not a security regression.
 */
final class SafeHrefDirective implements Directive
{
    public function keywords(): array
    {
        return ['safe_href'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        if (!isset($args['safe_href'])) {
            throw new \RuntimeException("Directive 'safe_href' missing href argument.");
        }
        return new Token('safe_href', ['expr' => $args['safe_href']]);
    }

    public function handles(): array
    {
        return ['safe_href'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $expr  = (string)$token->payload['expr'];
        $value = Expression::evaluate($expr, $ctx);
        $str   = Sanitizer::stringify($value);

        $sanitized = HrefSanitizer::check($str);
        if ($sanitized === null) {
            // Empty input is a normal authoring state (optional field
            // missing) — silent. Non-empty rejection is an authoring
            // error or a stored-XSS attempt — log it for review.
            if ($str !== '') {
                Log::warn('safe_href: rejected href', [
                    'expr'  => $expr,
                    // Truncate the value so a 2 KiB malicious payload
                    // doesn't bloat the log line.
                    'value' => mb_substr($str, 0, 200, 'UTF-8'),
                ]);
            }
            return '';
        }

        return Sanitizer::escape($sanitized);
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('SafeHrefDirective is not a block directive.');
    }
}
