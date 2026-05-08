<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Token;

/**
 * `{% if: <cond> %}` … `{% endif %}` — render the body when the
 * condition is truthy. Conditions accept ==, !=, &&, ||, ! and any
 * value expression. No else / elseif yet — straight gating only.
 *
 * One directive owns both the opening keyword (`if`) and the closing
 * keyword (`endif`). The token produced for the opener carries
 * closesWithType='if_close' so the Renderer pairs them without any
 * directive-side bookkeeping.
 */
final class IfDirective implements Directive
{
    public function keywords(): array
    {
        return ['if', 'endif'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        if ($keyword === 'endif') {
            return new Token('if_close', [], isClose: true);
        }
        if (!isset($args['if'])) {
            throw new \RuntimeException("Directive 'if' missing condition.");
        }
        return new Token(
            'if_open',
            ['cond' => $args['if']],
            closesWithType: 'if_close',
        );
    }

    public function handles(): array
    {
        return ['if_open'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('IfDirective is a block directive.');
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        $cond = Expression::evaluateCondition((string)$open->payload['cond'], $ctx);
        return Expression::truthy($cond) ? $renderer->renderTokens($body, $ctx) : '';
    }
}
