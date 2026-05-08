<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Immutable value object emitted by the Tokenizer and consumed by the
 * Renderer. The `type` is matched against registered Directive handlers;
 * `payload` carries directive-specific data (text body, expression,
 * include name, etc.).
 *
 * Block-pairing fields:
 *   - `closesWithType` — set on a block-opening token, names the matching
 *     close-token's type so the Renderer can collect the body without
 *     consulting the directive. Null for non-block tokens.
 *   - `isClose` — true on the matching close-token of a block. Lets the
 *     Renderer detect a stray close at top level.
 *
 * Putting these two on the Token (instead of asking the Directive) means
 * the Renderer dispatches purely by token-type lookup, with no callback
 * into the directive object to discover its block-shape.
 */
final class Token
{
    /**
     * @param string $type    Directive type name (e.g. "text", "var", "for_open").
     * @param array<string, mixed> $payload  Directive-specific data.
     * @param bool   $isClose True for matching close-tokens of block directives.
     * @param ?string $closesWithType  For block-opening tokens: the close-token
     *                                  type that ends this block. Null otherwise.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
        public readonly bool $isClose = false,
        public readonly ?string $closesWithType = null,
    ) {
    }
}
