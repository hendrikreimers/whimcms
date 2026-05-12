<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Log;
use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Engine;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `{% lookup: <map_expr>, key: <key_expr> %}` — evaluate two expressions
 * to a map and a runtime key, return the value at that key as
 * HTML-escaped output.
 *
 * Closes the engine's "no dynamic key access" gap: the Expression
 * sub-language supports only dot-paths with literal segments
 * (`URLS.imprint`), not bracket-style indexing with a runtime value
 * (`URLS[item.slug]`). Anywhere a template needs to resolve a value
 * from a map whose key only exists at render time — editor-driven nav
 * data referencing routed slugs, code → message lookups, etc. — this
 * directive is the supported form.
 *
 * Behaviour:
 *   - The map expression must evaluate to an array. Non-array maps
 *     (null, scalar, etc.) emit an empty string and warn — typically
 *     a typo or a deleted context variable.
 *   - The key is coerced to a string for the lookup
 *     (`Sanitizer::stringify`). Numeric keys work because PHP array
 *     access does the int↔string coercion automatically.
 *   - A key miss emits an empty string and warns. Empty `href=""` is
 *     a deliberately ugly fail state — visible-broken so an editor
 *     who typo'd a slug notices, but never producing a forged URL.
 *   - The looked-up value is stringified and HTML-escaped before
 *     emission, identical to `{{ }}`. Arrays/objects coerce to empty
 *     (same as `Sanitizer::stringify`'s default).
 *
 * Trust model: the directive does no path validation on the resolved
 * value. Use `{% safe_href %}` separately if the value lands in an
 * `href=`/`src=` attribute. A common pattern is the resolved URL is
 * already a server-built `URLS.<slug>` string — those are produced by
 * `Router::canonicalUrl` and don't need re-validation — so the
 * directive emits them straight (escaped) without re-running the
 * href allowlist.
 *
 * Text-mode (`Engine::renderText`) skips the escape pass, consistent
 * with `{{ }}` in plain-text mail bodies.
 */
final class LookupDirective implements Directive
{
    public function keywords(): array
    {
        return ['lookup'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        if (!isset($args['lookup'])) {
            throw new \RuntimeException("Directive 'lookup' missing map expression.");
        }
        if (!isset($args['key'])) {
            throw new \RuntimeException("Directive 'lookup' missing 'key' argument.");
        }
        return new Token('lookup', [
            'map' => $args['lookup'],
            'key' => $args['key'],
        ]);
    }

    public function handles(): array
    {
        return ['lookup'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $mapExpr = (string)$token->payload['map'];
        $keyExpr = (string)$token->payload['key'];

        $map = Expression::evaluate($mapExpr, $ctx);
        $key = Expression::evaluate($keyExpr, $ctx);

        if (!is_array($map)) {
            // Map expr resolved to null / scalar / etc. — almost always
            // a typo'd context variable. Log so a debug-mode operator
            // notices; empty output is the visible-broken fail-safe.
            Log::warn('lookup: map expression did not resolve to an array', [
                'mapExpr' => $mapExpr,
                'keyExpr' => $keyExpr,
            ]);
            return '';
        }

        // Key coerces to string; PHP array access maps "0" ↔ 0 transparently
        // so numeric keys still resolve. Null/array/object keys stringify
        // to '' which deliberately won't match anything sensible.
        $keyStr = Sanitizer::stringify($key);
        if (!array_key_exists($keyStr, $map)) {
            Log::warn('lookup: key not found in map', [
                'mapExpr' => $mapExpr,
                'keyExpr' => $keyExpr,
                'key'     => mb_substr($keyStr, 0, 200, 'UTF-8'),
            ]);
            return '';
        }

        $value = $map[$keyStr];
        $str   = Sanitizer::stringify($value);
        return !empty($ctx[Engine::TEXT_MODE_FLAG]) ? $str : Sanitizer::escape($str);
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('LookupDirective is not a block directive.');
    }
}
