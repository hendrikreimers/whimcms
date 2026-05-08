<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Token;

/**
 * Plain text between markers. Emitted verbatim — already trusted because
 * it comes straight from a template file we wrote.
 *
 * Not keyword-dispatched: the Tokenizer emits `text` tokens directly for
 * any non-marker run of source. keywords() returns [] and tokenize()
 * throws because it is never called.
 */
final class TextDirective implements Directive
{
    public function keywords(): array
    {
        return [];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        throw new \LogicException('TextDirective is not keyword-dispatched.');
    }

    public function handles(): array
    {
        return ['text'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        return (string)($token->payload['value'] ?? '');
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('TextDirective is not a block directive.');
    }
}
