<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Engine;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `{{ expr }}` — evaluate the expression against the current context and
 * emit the value HTML-escaped. The standard, safe variable output mode.
 *
 * In text-mode renders (set by Engine::renderText) escape is skipped so
 * the value lands in the output verbatim — the right thing for plain-text
 * mail bodies. Same opt-in/opt-out semantics as Twig's autoescape.
 *
 * Not keyword-dispatched: the Tokenizer emits `var` tokens directly for
 * `{{ … }}` markers.
 */
final class VarDirective implements Directive
{
    public function keywords(): array
    {
        return [];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        throw new \LogicException('VarDirective is not keyword-dispatched.');
    }

    public function handles(): array
    {
        return ['var'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $value = Expression::evaluate((string)$token->payload['expr'], $ctx);
        $str = Sanitizer::stringify($value);
        return !empty($ctx[Engine::TEXT_MODE_FLAG]) ? $str : Sanitizer::escape($str);
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('VarDirective is not a block directive.');
    }
}
