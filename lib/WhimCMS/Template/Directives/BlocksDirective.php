<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Content\Block;
use H42\WhimCMS\Content\Identifiers;
use H42\WhimCMS\Template\AnnotationConsumer;
use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Engine;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Token;

/**
 * `{% blocks %}` — render the page's content blocks in order.
 *
 * The directive expects the render context to carry a `BLOCKS` key holding
 * a list<Block>. For each block it looks up the partial template via the
 * Engine's BlockRegistry, builds a child context with:
 *
 *   - `attrs` ← the block's attribute map. Block partials read their
 *               attributes via `{{ attrs.<key> }}`. Same convention as
 *               the include directive's `attrs: <expr>` rebind, so
 *               sub-includes from a block partial behave identically.
 *   - `body`  ← the pre-rendered, sanitized HTML of the block's optional
 *               Markdown body. Block partials emit it via
 *               `{% html: body %}` (the audit-restricted verbatim
 *               directive — see HtmlDirective). Empty string when the
 *               block has no body.
 *
 * Anything else in the parent context (BASE, URLS, CACHE_BUSTER,
 * CURRENT_LANG, EMAIL, …) is inherited as-is so block partials can
 * produce links and reference shared resources without ceremony.
 *
 * Self-registration of block schemas (AnnotationConsumer hook):
 *
 *   The directive consumes `{@ block … @}` annotations from every file
 *   under `partials/blocks/*.html`. The block-type name is taken from
 *   the partial's filename (e.g. `partials/blocks/hero.html` → type
 *   `hero`); `required:` and `optional:` are space-separated lists of
 *   attribute names. The Engine runs the scan once at boot and calls
 *   consumeAnnotation() per partial; we forward each result to the
 *   shared BlockRegistry so the PageLoader can validate `.md` content
 *   against it on the parse path.
 *
 * Defensive shape checks:
 *   - Non-array BLOCKS → empty output, no error. Defence-in-depth in case
 *     the context is built on a path that didn't load a Page.
 *   - Items that aren't Block instances are skipped (also belt-and-braces).
 *   - An unregistered block type would have failed at PageLoader time, so
 *     reaching the partial-lookup path with an unknown type can only happen
 *     via a hand-edited cache file — we still throw, loud and clear.
 */
final class BlocksDirective implements Directive, AnnotationConsumer
{
    public function __construct(private Engine $engine)
    {
    }

    public function keywords(): array
    {
        return ['blocks'];
    }

    public function tokenize(string $keyword, array $args): Token
    {
        return new Token('blocks');
    }

    public function handles(): array
    {
        return ['blocks'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $blocks = $ctx['BLOCKS'] ?? null;
        if (!is_array($blocks)) {
            return '';
        }
        $registry = $this->engine->blocks();
        $out = '';
        foreach ($blocks as $block) {
            if (!$block instanceof Block) {
                continue;
            }
            $partial = $registry->partialFor($block->type);
            $childCtx = $ctx;
            $childCtx['attrs'] = $block->attrs;
            $childCtx['body']  = $block->body;
            $out .= $renderer->renderTemplate($partial, $childCtx);
        }
        return $out;
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('BlocksDirective is not a block directive.');
    }

    // ---------- AnnotationConsumer ---------------------------------------

    public function annotationNames(): array
    {
        return ['block'];
    }

    public function eagerScanPaths(): array
    {
        return ['partials/blocks/*.html'];
    }

    /**
     * Register one block-type schema from a partial's `{@ block @}` header.
     *
     *   - $templateName comes in as e.g. "partials/blocks/hero" — the
     *     basename is the block-type name, validated against
     *     Identifiers::BLOCK_TYPE_PATTERN.
     *   - $payload is a flat string→string map; only `required` and
     *     `optional` are recognised today, both interpreted as
     *     space-separated lists of attribute names. An unexpected key
     *     is a boot-time error so a typo'd `requireed:` doesn't
     *     silently produce a block with no required attributes.
     *
     * @param array<string, string> $payload
     */
    public function consumeAnnotation(string $templateName, array $payload): void
    {
        $type = basename($templateName);
        if (!Identifiers::isValidBlockType($type)) {
            throw new \RuntimeException(
                "Block partial filename '{$type}' is not a valid block-type name."
            );
        }

        foreach (array_keys($payload) as $key) {
            if ($key !== 'required' && $key !== 'optional') {
                throw new \RuntimeException(
                    "Block annotation in '{$templateName}' has unknown key '{$key}' (allowed: required, optional)."
                );
            }
        }

        $required = $this->splitNames($payload['required'] ?? '', $templateName, 'required');
        $optional = $this->splitNames($payload['optional'] ?? '', $templateName, 'optional');

        $this->engine->blocks()->register($type, $templateName, $required, $optional);
    }

    /**
     * Split a space-separated list of attribute names into a list. The
     * empty string yields []. Each name is validated against the same
     * key pattern AttributeParser uses for content attributes, so a
     * misspelled or syntactically invalid entry fails loud at boot.
     *
     * @return list<string>
     */
    private function splitNames(string $raw, string $templateName, string $field): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $raw);
        if ($parts === false) {
            return [];
        }
        $out = [];
        foreach ($parts as $name) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $name) !== 1) {
                throw new \RuntimeException(
                    "Block annotation in '{$templateName}': '{$field}' contains invalid attribute name '{$name}'."
                );
            }
            $out[] = $name;
        }
        return $out;
    }
}
