<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Log;
use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Token;

/**
 * `{% alias: { name: <expr>, … } %}` … `{% endalias %}` — bind one or
 * more names to expression results for the duration of the body.
 *
 * The aliases are merged on top of the current context for the body
 * render only. When the block closes, the parent context is unaffected
 * because PHP arrays are value-types — the directive never mutates the
 * caller's `$ctx`. This is the same per-scope binding mechanism that
 * `for` uses for `loop`/`item`/`attrs`, applied without iteration.
 *
 * Use cases:
 *   - Avoid re-evaluating the same expression several times in a body
 *     ("`PAGE == item.slug` twice — once for the class, once for the
 *     aria-current — becomes one `isCurrent` alias").
 *   - Give a long path lookup a short local name for readability.
 *   - Stage a `{% lookup %}`-style result before reusing it (the
 *     lookup directive itself emits output, this binds a value; the
 *     two combine when the alias body needs the looked-up value).
 *
 * Argument shape:
 *   The single argument must be an object literal whose values are
 *   expressions — `{ a: <expr>, b: <expr>, … }`. Object literals are
 *   already supported by the Expression sub-language; nothing new in
 *   the grammar.
 *
 * Forgiving runtime: if the argument doesn't evaluate to an array
 * (someone passed a scalar / null path), the body still renders, but
 * with no new bindings. Inside the body, references to the missing
 * names just resolve to null — the same behaviour as any other
 * missing path. A warning is logged so debug-mode operators see the
 * misuse without the page 500ing.
 *
 * Scope model (lexical, no Renderer state):
 *   - Body sees the parent context PLUS the merged aliases.
 *   - `{% include %}` inside the body still gets the entire child
 *     context (parent + aliases) because `IncludeDirective` already
 *     copies `$ctx` for the child render. This matches existing
 *     `attrs`/`for`-binding behaviour and is the principle of least
 *     surprise — what the body can see, an include from inside the
 *     body can see too. Use the `attrs:` argument of `{% include %}`
 *     to be explicit when stricter isolation is wanted.
 *   - Outside the block, the parent context is unchanged. No
 *     "set"-like mutation of caller state.
 *
 * The block always renders its body, even when the aliases expression
 * is empty or evaluates to a non-array. `alias` is a binding form, not
 * an iteration form — there is no "no items, skip body" semantics.
 */
final class AliasDirective implements Directive
{
    public function keywords(): array
    {
        return ['alias', 'endalias'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        if ($keyword === 'endalias') {
            return new Token('alias_close', [], isClose: true);
        }
        if (!isset($args['alias'])) {
            throw new \RuntimeException("Directive 'alias' missing bindings expression.");
        }
        return new Token(
            'alias_open',
            ['expr' => $args['alias']],
            closesWithType: 'alias_close',
        );
    }

    public function handles(): array
    {
        return ['alias_open'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('AliasDirective is a block directive.');
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        $expr  = (string)$open->payload['expr'];
        $value = Expression::evaluate($expr, $ctx);

        if (!is_array($value)) {
            // Misuse — alias arg must evaluate to an associative array
            // (typically an object literal `{ x: …, y: … }`). Body
            // still renders so a single bad alias doesn't 500 the
            // page; missing names just resolve to null inside the body.
            Log::warn('alias: bindings expression did not resolve to an array', [
                'expr' => $expr,
            ]);
            return $renderer->renderTokens($body, $ctx);
        }

        // Merge aliases on top of the inherited context. PHP's
        // array_merge with associative keys overwrites, which is
        // exactly the semantics we want — an alias `URLS` would
        // shadow the global, an alias `PAGE` would shadow the current
        // slug, and so on. Authors are responsible for not shadowing
        // names they still need inside the body.
        $childCtx = array_merge($ctx, $value);
        return $renderer->renderTokens($body, $childCtx);
    }
}
