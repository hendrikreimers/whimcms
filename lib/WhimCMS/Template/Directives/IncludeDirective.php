<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `{% include: 'partials/x' %}` — render another template with the
 * current scope.
 *
 * `{% include: 'partials/x', attrs: <expr> %}` — additionally rebind
 * the `attrs` slot for the inclusion. The included template inherits
 * the entire root scope (CURRENT_LANG, BASE, PAGE, MULTI_LANG, …);
 * only the `attrs` slot can be overridden.
 *
 * The `attrs` convention:
 *   - `CURRENT_LANG` — global, immutable; the language dictionary loaded
 *     from `<paths.i18n>/<lang>.json`. Use `{{ CURRENT_LANG.contact.title }}`
 *     for translation strings.
 *   - `attrs` — local, sub-scope; the data the current partial is
 *     rendering. Bound by BlocksDirective (block attrs from .md),
 *     ForDirective (per-iteration item for inline-include), and this
 *     directive's `attrs:` argument. Use `{{ attrs.title }}` for
 *     partial-local data.
 */
final class IncludeDirective implements Directive
{
    public function keywords(): array
    {
        return ['include'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        return new Token('include', [
            'name'  => $args['include'],
            'attrs' => $args['attrs'] ?? null,
        ]);
    }

    public function handles(): array
    {
        return ['include'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $name = Sanitizer::stringify(Expression::evaluate((string)$token->payload['name'], $ctx));
        $childCtx = $ctx;
        $attrsExpr = $token->payload['attrs'] ?? null;
        if (is_string($attrsExpr) && $attrsExpr !== '') {
            $childCtx['attrs'] = Expression::evaluate($attrsExpr, $ctx);
        }
        return $renderer->renderTemplate($name, $childCtx);
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('IncludeDirective is not a block directive.');
    }
}
