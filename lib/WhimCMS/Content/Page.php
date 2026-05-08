<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Immutable value object: a fully-loaded, validated, render-ready page.
 *
 * - `header` carries the page-level configuration parsed from the front-matter
 *   block at the top of the .md file. Recognised keys today are `layout`
 *   (whitelisted by PageLoader) and `meta` (`title`, `description`); unknown
 *   top-level keys are rejected at load time so typos fail loud.
 * - `blocks` is the in-order list of content blocks, each one already validated
 *   against the BlockRegistry's per-type schema and with its body pre-rendered
 *   to safe HTML. The layout template iterates this list via the `{% blocks %}`
 *   directive — no per-page template is involved.
 *
 * Page is what PageLoader returns and what the cache layer stores (as a plain
 * array via var_export, then reconstructed). Block instances inside follow the
 * same contract.
 */
final class Page
{
    /**
     * @param array<string, mixed> $header
     * @param list<Block>          $blocks
     */
    public function __construct(
        public readonly array $header,
        public readonly array $blocks,
    ) {
    }

    public function layout(): string
    {
        $v = $this->header['layout'] ?? 'default';
        return is_string($v) && $v !== '' ? $v : 'default';
    }

    /**
     * @return array<string, string>
     */
    public function meta(): array
    {
        $m = $this->header['meta'] ?? null;
        if (!is_array($m)) {
            return ['title' => '', 'description' => ''];
        }
        return [
            'title'       => is_string($m['title']       ?? null) ? $m['title']       : '',
            'description' => is_string($m['description'] ?? null) ? $m['description'] : '',
        ];
    }
}
