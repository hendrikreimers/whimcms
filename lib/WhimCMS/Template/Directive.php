<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Strategy interface implemented by every directive. Splits cleanly into
 * three phases:
 *
 *   1. PARSE  — keywords() declares which {% ... %}-body keywords this
 *               directive owns; tokenize() turns a matched body into a Token.
 *   2. ROUTE  — handles() declares which token types end up dispatched to
 *               this directive at render time.
 *   3. RENDER — render() / renderBlock() produce the output string.
 *
 * Self-registration:
 *   The Engine collects every directive listed in BuiltInDirectives::all()
 *   and builds two maps from them:
 *     - keyword → directive  (for the Tokenizer to dispatch {% kw … %})
 *     - token-type → directive  (for the Renderer to dispatch tokens)
 *   Conflicts in either map are boot-time errors. There is no global
 *   list of keywords or token types maintained anywhere else — adding
 *   a directive class to BuiltInDirectives::all() is the entire wiring.
 *
 * Conventions:
 *   - Output-only directives (Text/Var/Raw — emitted by the Tokenizer
 *     directly for plain text and {{ }}/{!! !!}) return [] from
 *     keywords() and throw from tokenize() since they are not
 *     keyword-dispatched.
 *   - Non-block directives throw from renderBlock(); block directives
 *     throw from render(). The Renderer never reaches the wrong method
 *     on a correctly-implemented directive because dispatch is driven
 *     by the token's `closesWithType` field.
 */
interface Directive
{
    /**
     * Body keywords this directive owns inside {% ... %}. The Engine
     * maps each entry to this directive at boot. Two directives claiming
     * the same keyword is a boot-time error.
     *
     * Directives that are produced directly by the Tokenizer (Text/Var/Raw)
     * are not keyword-dispatched and return [].
     *
     * @return list<string>
     */
    public function keywords(): array;

    /**
     * Turn a matched directive body into a Token. Receives the matched
     * keyword and the parsed key→raw-expression map (or [] for bare-keyword
     * forms like {% endif %}). Implementations should set the Token's
     * `closesWithType` for block-opens and `isClose` for closes.
     *
     * Directives that don't participate in keyword dispatch throw a
     * LogicException — they're never asked.
     *
     * @param array<string, string> $args
     */
    public function tokenize(string $keyword, array $args): Token;

    /**
     * Token types this directive renders. The Engine builds a token-type →
     * directive map from this; conflicts are boot-time errors.
     *
     * @return list<string>
     */
    public function handles(): array;

    /**
     * Render a non-block token. Block directives throw from here — the
     * Renderer routes block-opens to renderBlock() instead.
     *
     * @param array<string, mixed> $ctx
     */
    public function render(Token $token, array $ctx, Renderer $renderer): string;

    /**
     * Render a block-opening token together with its collected body.
     * Non-block directives throw from here. The Renderer balances nested
     * same-type opens before calling this.
     *
     * @param list<Token>           $body
     * @param array<string, mixed>  $ctx
     */
    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string;
}
