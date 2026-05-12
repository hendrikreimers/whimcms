<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Config;
use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Engine;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `{% debug: _all %}` — dump the entire render context as pretty-printed
 * JSON inside a `<pre class="debug">` block. Excluded top-level keys
 * (CSRF token, captcha state, honeypot field name) are filtered out.
 *
 * `{% debug: <path> %}` — dump a single value at the given dot-path,
 * same formatting. Any path whose first segment matches an excluded
 * top-level key renders `[excluded by debug policy]` instead — the
 * exclusion applies whether the dump is enumerative (`_all`) or
 * targeted.
 *
 * Two gates, both required for output:
 *   1. `config/app.php → debug` is truthy. When false the directive
 *      is a silent no-op — a forgotten `{% debug %}` left in a
 *      production template emits nothing, never crashes, never leaks.
 *   2. Not in text-mode render (mail-template flag absent). Debug
 *      output belongs to HTML pages; embedding JSON dumps in a plain-
 *      text mail body is always a mistake.
 *
 * Security notes:
 *   - Output is `htmlspecialchars`'d even though the surrounding tag
 *     is `<pre>`. Without escape, a value containing `</pre><script>…`
 *     would break out of the sandbox tag and execute. Defense-in-depth:
 *     gated PLUS escaped, never one without the other.
 *   - The exclusion list is a hard floor, not a suggestion. Anything
 *     that could authenticate the current request (CSRF token,
 *     captcha nonce/salt, honeypot field name derived from secret)
 *     stays out, regardless of how it's requested. Adding to the list
 *     is cheap; removing requires a deliberate security review.
 *   - `_all` enumerates top-level keys only. Nested sensitive data
 *     (none present today) would need its own filter pass.
 *   - The directive never throws on an evaluation miss — a missing
 *     path dumps `null`, same as `{{ }}`. Loud failures in dev would
 *     be welcome but would also encourage commenting out debug calls
 *     instead of fixing the path, which makes debugging worse not
 *     better.
 *
 * Style:
 *   Output uses `<pre class="debug">` so themes can style the dump
 *   (background, monospace, max-height with scroll) if they want.
 *   No baseline CSS is bundled; the block renders perfectly readable
 *   even with no styles applied.
 */
final class DebugDirective implements Directive
{
    /**
     * Top-level context keys that must never be emitted by this
     * directive, regardless of how the dump is requested.
     *
     * Reason each key is here:
     *   - FORM_TOKEN     : CSRF token, allows authenticated POST replay
     *   - CAPTCHA        : proof-of-work nonce + salt + difficulty; an
     *                      attacker who sees the salt can pre-compute
     *                      solutions for the form's grace period
     *   - HONEYPOT_FIELD : the field name is derived from the server
     *                      secret; leaking it lets a bot trivially
     *                      avoid the trap
     *
     * Adding a key here is a cheap defensive move. Removing one is a
     * security decision that should be justified in code review.
     */
    private const EXCLUDED_KEYS = [
        'FORM_TOKEN',
        'CAPTCHA',
        'HONEYPOT_FIELD',
    ];

    /**
     * Sentinel: the bare identifier `_all` requests an enumeration of
     * the entire context (minus exclusions). Special-cased before
     * evaluation because there's no actual `_all` context key — a
     * normal Expression::evaluate would just return null.
     */
    private const SENTINEL_ALL = '_all';

    public function keywords(): array
    {
        return ['debug'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        if (!isset($args['debug'])) {
            throw new \RuntimeException("Directive 'debug' missing target expression.");
        }
        return new Token('debug', ['expr' => $args['debug']]);
    }

    public function handles(): array
    {
        return ['debug'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        // Hard gate: production renders nothing. The check is per-call
        // so a debug=true toggle takes effect on the next request
        // without reboot. Cost is one config lookup per directive
        // invocation — negligible.
        if (!Config::get('debug', false)) {
            return '';
        }
        // Text-mode (mail bodies) never gets debug output — dumping
        // JSON into a plain-text email body would be obvious noise but
        // also bypasses our HTML-escape pass, which we depend on for
        // the <pre> sandbox.
        if (!empty($ctx[Engine::TEXT_MODE_FLAG])) {
            return '';
        }

        $expr = trim((string)$token->payload['expr']);

        if ($expr === self::SENTINEL_ALL) {
            return $this->renderAll($ctx);
        }
        return $this->renderPath($expr, $ctx);
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('DebugDirective is not a block directive.');
    }

    /**
     * Dump every top-level context key whose name is not excluded.
     *
     * @param array<string, mixed> $ctx
     */
    private function renderAll(array $ctx): string
    {
        $safe = [];
        foreach ($ctx as $key => $value) {
            if (in_array($key, self::EXCLUDED_KEYS, true)) {
                continue;
            }
            $safe[$key] = $value;
        }
        return $this->wrap($this->encode($safe));
    }

    /**
     * Dump a single value at the given path. If the path's first
     * segment names an excluded key, emit the excluded marker instead
     * of evaluating — even direct requests stay subject to the policy.
     *
     * @param array<string, mixed> $ctx
     */
    private function renderPath(string $path, array $ctx): string
    {
        $firstSegment = explode('.', $path, 2)[0];
        if (in_array($firstSegment, self::EXCLUDED_KEYS, true)) {
            return $this->wrap('[excluded by debug policy: ' . $firstSegment . ']');
        }
        $value = Expression::evaluate($path, $ctx);
        return $this->wrap($this->encode([$path => $value]));
    }

    /**
     * Wrap a body in a `<pre class="debug">`, escaping the body. The
     * escape pass is what makes the wrapper safe: without it, a value
     * containing `</pre><script>…` would break out.
     */
    private function wrap(string $body): string
    {
        return '<pre class="debug">' . Sanitizer::escape($body) . '</pre>';
    }

    /**
     * Pretty-print any value as JSON with stable, human-readable
     * formatting. JSON_PARTIAL_OUTPUT_ON_ERROR keeps a single bad
     * value (e.g. a resource handle) from failing the whole dump —
     * the offending field becomes null, the rest survives.
     */
    private function encode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return $json === false ? '[debug: unable to encode value]' : $json;
    }
}
