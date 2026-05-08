<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `for` — two surface forms, one keyword owner, one class:
 *
 *   1. Block form
 *      `{% for: <expr>, as: 'name' %}` … `{% endfor %}`
 *      Inline loop with a body. The current item is bound under the
 *      explicit `as` name (e.g. `{{ item.x }}`); `as` is mandatory.
 *      `{{ loop.index/first/last }}` is always available.
 *
 *   2. Inline-include form
 *      `{% for: <expr>, as: 'name', include: 'partials/x' %}`
 *      Render the named partial once per item. The current item is
 *      bound as `attrs` so the partial reads it via the standard
 *      `{{ attrs.… }}` convention; the `as` name is also bound for
 *      symmetry with the block form.
 *
 * Why `as` is mandatory in both forms:
 *   - Block form: the body inside `{% for %}…{% endfor %}` references
 *     the item explicitly, so an explicit name is the right thing.
 *   - Inline-include form: the included partial reads `attrs.…`, so
 *     `as` is technically redundant — but requiring it keeps one rule
 *     ("`for` always has `as`") instead of two, and lets a reviewer see
 *     at a glance what the loop is binding without knowing the form.
 *
 * The `attrs` rebind happens ONLY for inline-include, not for block
 * form. Block form uses the explicit `as` name and does not silently
 * shadow the parent `attrs` slot — so a block partial's outer attrs
 * remain reachable from inside `{% for %}` loops in that partial.
 *
 * Non-iterable values render nothing — same as a missing path lookup.
 */
final class ForDirective implements Directive
{
    public function keywords(): array
    {
        return ['for', 'endfor'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        if ($keyword === 'endfor') {
            return new Token('for_close', [], isClose: true);
        }
        if (!isset($args['for'])) {
            throw new \RuntimeException("Directive 'for' missing iterator.");
        }
        if (!isset($args['as'])) {
            throw new \RuntimeException("Directive 'for' missing 'as' binding (mandatory).");
        }
        $payload = [
            'iter' => $args['for'],
            'as'   => Expression::stripQuotes($args['as']),
        ];
        if (isset($args['include'])) {
            $payload['name'] = $args['include'];
            return new Token('for_inline_include', $payload);
        }
        return new Token('for_open', $payload, closesWithType: 'for_close');
    }

    public function handles(): array
    {
        return ['for_open', 'for_inline_include'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        // Inline-include form has no body — handled here. The block form
        // is routed to renderBlock() because its token carries
        // closesWithType.
        if ($token->type !== 'for_inline_include') {
            throw new \LogicException("ForDirective::render called on unexpected type: {$token->type}");
        }
        $items = $this->materialise($token, $ctx);
        if ($items === null) {
            return '';
        }
        $name = Sanitizer::stringify(Expression::evaluate((string)$token->payload['name'], $ctx));
        $as   = (string)$token->payload['as'];

        $out   = '';
        $count = count($items);
        foreach ($items as $idx => $item) {
            $childCtx = $this->bindIteration($ctx, $item, $idx, $count, $as, /*bindAttrs*/ true);
            $out .= $renderer->renderTemplate($name, $childCtx);
        }
        return $out;
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        $items = $this->materialise($open, $ctx);
        if ($items === null) {
            return '';
        }
        $as = (string)$open->payload['as'];

        $out   = '';
        $count = count($items);
        foreach ($items as $idx => $item) {
            $childCtx = $this->bindIteration($ctx, $item, $idx, $count, $as, /*bindAttrs*/ false);
            $out .= $renderer->renderTokens($body, $childCtx);
        }
        return $out;
    }

    /**
     * Resolve the iterator expression and materialise it into a 0-indexed
     * array. Returns null when the iterator is missing or empty; callers
     * treat that as "render nothing".
     *
     * @param array<string, mixed> $ctx
     * @return list<mixed>|null
     */
    private function materialise(Token $token, array $ctx): ?array
    {
        $iter = Expression::evaluate((string)$token->payload['iter'], $ctx);
        if (!is_iterable($iter)) {
            return null;
        }
        $items = is_array($iter) ? array_values($iter) : iterator_to_array($iter, false);
        return $items === [] ? null : $items;
    }

    /**
     * Build the per-iteration child context. `as` is always bound; for
     * inline-include the item is additionally bound to `attrs` so the
     * generic partial can read it via `{{ attrs.… }}`. Block form does
     * not rebind `attrs`, leaving the parent's attrs (e.g. the block's
     * own attribute map) reachable from inside the loop body.
     *
     * `loop.{index,first,last}` is always set.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function bindIteration(array $ctx, mixed $item, int $idx, int $count, string $as, bool $bindAttrs): array
    {
        $ctx[$as] = $item;
        if ($bindAttrs) {
            $ctx['attrs'] = $item;
        }
        $ctx['loop'] = [
            'index' => $idx,
            'first' => $idx === 0,
            'last'  => $idx === $count - 1,
        ];
        return $ctx;
    }
}
