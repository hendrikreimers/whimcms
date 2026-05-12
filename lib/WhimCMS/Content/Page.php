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

    /**
     * Soft-hide flag from front-matter `hidden: true`.
     *
     * A hidden page renders normally when its URL is requested
     * (it's not "deleted") — but it's excluded from sitemap.xml,
     * and is delivered to nav-rendering templates with a
     * `hidden: true` flag so the template author can choose to
     * skip it. Typical use: landing pages reachable only via
     * direct link / campaign URL.
     *
     * The front-matter parser stores every value as a string, so
     * we accept `true` / `yes` / `1` as truthy. Anything else
     * (including missing) is false.
     */
    public function hidden(): bool
    {
        return self::truthy($this->header['hidden'] ?? null);
    }

    /**
     * Hard-disable flag from front-matter `disabled: true`.
     *
     * A disabled page is delivered as 404 when its URL is
     * requested. It is also excluded from sitemap.xml and from
     * the language switcher. Use this to retire a page without
     * deleting it — restore by removing the flag.
     */
    public function disabled(): bool
    {
        return self::truthy($this->header['disabled'] ?? null);
    }

    /**
     * Coerce the front-matter's string representation of a boolean
     * (`true` / `yes` / `1`) into a real bool. Anything else is
     * false — including the absence of the key entirely.
     */
    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (!is_string($v)) return false;
        $v = strtolower(trim($v));
        return $v === 'true' || $v === 'yes' || $v === '1';
    }
}
