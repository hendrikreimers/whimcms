<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Token-stream interpreter. Holds a token-type → directive map and
 * dispatches each token to its handler. Block directives get their body
 * collected here so the directive itself can stay focused on what to *do*
 * with it.
 *
 * Block-pairing is driven entirely by the Token: a block-opening token
 * carries `closesWithType` naming its matching close-token type. The
 * Renderer never asks the Directive for that pairing — it reads it off
 * the token. This keeps the dispatch loop a pure type-table lookup.
 */
final class Renderer
{
    /**
     * @param array<string, Directive> $directives  Token-type → directive handler.
     *                                              Built once by the Engine, immutable thereafter.
     */
    public function __construct(
        private Engine $engine,
        private array $directives,
    ) {
    }

    /**
     * Walk a token list and produce the rendered output. Block tokens are
     * paired with their close tokens here; close tokens at top level are
     * an error (someone wrote `{% endif %}` without an `if`).
     *
     * @param list<Token>           $tokens
     * @param array<string, mixed>  $ctx
     */
    public function renderTokens(array $tokens, array $ctx): string
    {
        $out = '';
        $i = 0;
        $n = count($tokens);

        while ($i < $n) {
            $token = $tokens[$i];

            if ($token->isClose) {
                throw new \RuntimeException("Unexpected close token at index {$i}: {$token->type}");
            }

            $directive = $this->directives[$token->type] ?? null;
            if ($directive === null) {
                throw new \RuntimeException("No directive registered for token type: {$token->type}");
            }

            if ($token->closesWithType !== null) {
                [$body, $next] = $this->collectBlock($tokens, $i, $token->type, $token->closesWithType);
                $out .= $directive->renderBlock($token, $body, $ctx, $this);
                $i = $next;
            } else {
                $out .= $directive->render($token, $ctx, $this);
                $i++;
            }
        }
        return $out;
    }

    /**
     * Render a named template under the given context. Used by include
     * and for-include directives, which delegate back to the Engine via
     * this method so the cache and template-dir live in one place.
     *
     * @param array<string, mixed> $ctx
     */
    public function renderTemplate(string $name, array $ctx): string
    {
        return $this->engine->render($name, $ctx);
    }

    /**
     * Collect the body tokens of a block, balancing nested same-type
     * opens. Returns [body tokens, index just past the close].
     *
     * @param list<Token> $tokens
     * @return array{0: list<Token>, 1: int}
     */
    private function collectBlock(array $tokens, int $openIdx, string $openType, string $closeType): array
    {
        $body  = [];
        $depth = 1;
        $j     = $openIdx + 1;
        $n     = count($tokens);

        while ($j < $n && $depth > 0) {
            $type = $tokens[$j]->type;
            if ($type === $openType) {
                $depth++;
            } elseif ($type === $closeType) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $body[] = $tokens[$j];
            $j++;
        }
        if ($depth !== 0) {
            throw new \RuntimeException("Unclosed block: {$openType}");
        }
        return [$body, $j + 1];
    }
}
