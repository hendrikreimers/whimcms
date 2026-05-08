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
 * `{!! expr !!}` — evaluate and emit with the em-only sanitiser. Intended
 * for the handful of i18n strings that highlight a word with <em>. No
 * other tags or attributes survive — they end up escaped as text.
 *
 * In text-mode renders (Engine::renderText) the em sanitiser is skipped
 * so the source string is emitted verbatim. Plain-text mails don't
 * render HTML so escaping HTML specials would only litter the output
 * with `&amp;` etc.
 *
 * Not keyword-dispatched: the Tokenizer emits `raw` tokens directly for
 * `{!! … !!}` markers.
 */
final class RawDirective implements Directive
{
    public function keywords(): array
    {
        return [];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        throw new \LogicException('RawDirective is not keyword-dispatched.');
    }

    public function handles(): array
    {
        return ['raw'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $value = Expression::evaluate((string)$token->payload['expr'], $ctx);
        $str = Sanitizer::stringify($value);
        return !empty($ctx[Engine::TEXT_MODE_FLAG]) ? $str : Sanitizer::sanitizeEm($str);
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('RawDirective is not a block directive.');
    }
}
