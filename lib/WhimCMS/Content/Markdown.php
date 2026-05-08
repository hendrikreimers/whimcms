<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Safe-subset Markdown renderer.
 *
 * Supported, deliberately small surface:
 *
 *   Block-level:
 *     - Paragraphs (text separated by blank lines)
 *     - ATX headings: ## h2, ### h3, #### h4 only.
 *       (h1 is reserved for hero/sub-hero blocks; h5/h6 disallowed.)
 *     - Unordered lists: each item on its own line, prefixed with "- ".
 *     - Fenced code blocks: opened by ``` (optionally followed by a
 *       language identifier matching [a-z0-9_+-]+), closed by a line
 *       containing only ```. Every character inside is HTML-escaped
 *       and emitted verbatim — no inline parsing, no path markers,
 *       no link processing. Used by code-snippet blocks whose `code`
 *       attribute is too long to fit in a single attribute scalar.
 *
 *   Inline:
 *     - **strong**
 *     - *em*
 *     - `code`
 *     - [text](href) with allowlisted schemes only
 *
 * Everything else — raw HTML, images via ![](), ordered lists, blockquotes,
 * setext headings, tables, footnotes, autolinks, reference-style links,
 * HTML entities, escape sequences — is **not** parsed and produces literal,
 * HTML-escaped output. This is the entire feature set; anything beyond it
 * needs a security review before being added.
 *
 * Output is HTML-escaped at every boundary: the only literal `<` / `>` in
 * the result come from the limited tag set this class emits itself. Even
 * the URL inside `<a href="…">` is htmlspecialchars-escaped, on top of the
 * scheme allowlist.
 *
 * Path-marker resolution (~/foo → langRoot, ^/foo → basePath) happens here
 * for link hrefs. Attribute strings already had their markers resolved by
 * PageLoader before this renderer ever sees them — link hrefs are the only
 * place markers can survive into Markdown body content.
 */
final class Markdown
{
    /** Defence-in-depth — block parser already enforces a smaller per-section bound. */
    private const MAX_INPUT_BYTES = 262144;
    /** Cap inline recursion (e.g. **foo *bar* baz**) so a pathological input cannot blow the stack. */
    private const MAX_INLINE_DEPTH = 3;

    public function __construct(
        private readonly string $langRoot,
        private readonly string $basePath,
    ) {
    }

    /**
     * Render a block of Markdown to HTML. Empty input → empty output.
     */
    public function render(string $src): string
    {
        if ($src === '') {
            return '';
        }
        if (strlen($src) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Markdown body exceeds the maximum size.');
        }
        // Strip BOM and CR; we work in LF-only.
        $src = preg_replace('/^\xEF\xBB\xBF/', '', $src) ?? $src;
        $src = str_replace("\r\n", "\n", $src);
        $src = str_replace("\r", "\n", $src);
        if (strpos($src, "\0") !== false) {
            throw new \RuntimeException('Markdown body contains a null byte.');
        }
        // Defence-in-depth: PageLoader already validated UTF-8 at file
        // boundary, but the renderer is reachable from other code paths
        // in the future (mail bodies, tool output, …). Reject non-UTF-8
        // here too so ENT_SUBSTITUTE never silently mangles output.
        // renderInline()'s byte-level loop also depends on this — its
        // non-ASCII pass-through (see the fallthrough at the bottom of
        // the loop) assumes every >=0x80 byte is part of a complete,
        // valid UTF-8 sequence. Don't relax this check without revisiting
        // that branch.
        if (preg_match('//u', $src) !== 1) {
            throw new \RuntimeException('Markdown body is not valid UTF-8.');
        }

        $lines = explode("\n", $src);
        $blocks = $this->blockize($lines);
        $out = [];
        foreach ($blocks as $b) {
            $out[] = $this->renderBlock($b);
        }
        return implode("\n", $out);
    }

    /**
     * @param list<string> $lines
     * @return list<array{type: string, ...}>
     */
    private function blockize(array $lines): array
    {
        $blocks = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // ATX heading (## | ### | ####). Only one line.
            if (preg_match('/^(#{2,4}) +(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => $m[2]];
                $i++;
                continue;
            }

            // Fenced code block — ``` optionally followed by a language tag.
            // Closed by a line containing only ```. Body is captured verbatim.
            if (preg_match('/^```([a-z0-9_+\-]*)\s*$/', $line, $m) === 1) {
                $lang = $m[1];
                $i++;
                $body = [];
                while ($i < $n && trim($lines[$i]) !== '```') {
                    $body[] = $lines[$i];
                    $i++;
                }
                if ($i < $n) {
                    // Skip the closing fence.
                    $i++;
                }
                $blocks[] = ['type' => 'code', 'lang' => $lang, 'text' => implode("\n", $body)];
                continue;
            }

            // Unordered list — every consecutive line starting with "- ".
            if (str_starts_with($line, '- ')) {
                $items = [];
                while ($i < $n && str_starts_with($lines[$i], '- ')) {
                    $items[] = substr($lines[$i], 2);
                    $i++;
                }
                $blocks[] = ['type' => 'list', 'items' => $items];
                continue;
            }

            // Paragraph — gather non-blank, non-heading, non-list lines.
            $buf = [];
            while ($i < $n) {
                $ln = $lines[$i];
                if (trim($ln) === '') {
                    break;
                }
                if (preg_match('/^#{2,4} +/', $ln) === 1) {
                    break;
                }
                if (str_starts_with($ln, '- ')) {
                    break;
                }
                $buf[] = $ln;
                $i++;
            }
            $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $buf)];
        }
        return $blocks;
    }

    /**
     * @param array{type: string, ...} $block
     */
    private function renderBlock(array $block): string
    {
        switch ($block['type']) {
            case 'heading':
                /** @var int $level */
                $level = $block['level'];
                /** @var string $text */
                $text = $block['text'];
                return '<h' . $level . '>' . $this->renderInline($text) . '</h' . $level . '>';

            case 'list':
                /** @var list<string> $items */
                $items = $block['items'];
                $rendered = [];
                foreach ($items as $it) {
                    $rendered[] = '  <li>' . $this->renderInline($it) . '</li>';
                }
                return "<ul>\n" . implode("\n", $rendered) . "\n</ul>";

            case 'paragraph':
                /** @var string $text */
                $text = $block['text'];
                return '<p>' . $this->renderInline($text) . '</p>';

            case 'code':
                /** @var string $text */
                $text = $block['text'];
                /** @var string $lang */
                $lang = $block['lang'];
                $cls = $lang === '' ? '' : ' class="language-' . self::esc($lang) . '"';
                return '<pre><code' . $cls . '>' . self::esc($text) . '</code></pre>';
        }
        return '';
    }

    /**
     * Render inline-level constructs to safe HTML. Linear left-to-right
     * scan with a small state machine — no regex sprinkling that could be
     * tricked by overlapping markers.
     */
    private function renderInline(string $src, int $depth = 0): string
    {
        if ($depth >= self::MAX_INLINE_DEPTH) {
            return self::esc($src);
        }
        $i = 0;
        $n = strlen($src);
        $out = '';

        while ($i < $n) {
            $c = $src[$i];

            // Code span: `…`
            if ($c === '`') {
                $end = strpos($src, '`', $i + 1);
                if ($end !== false && $end > $i + 1) {
                    $content = substr($src, $i + 1, $end - $i - 1);
                    $out .= '<code>' . self::esc($content) . '</code>';
                    $i = $end + 1;
                    continue;
                }
                $out .= self::esc($c);
                $i++;
                continue;
            }

            // Strong / em
            if ($c === '*') {
                if ($i + 1 < $n && $src[$i + 1] === '*') {
                    $end = strpos($src, '**', $i + 2);
                    if ($end !== false && $end > $i + 2) {
                        $content = substr($src, $i + 2, $end - $i - 2);
                        $out .= '<strong>' . $this->renderInline($content, $depth + 1) . '</strong>';
                        $i = $end + 2;
                        continue;
                    }
                    $out .= self::esc('**');
                    $i += 2;
                    continue;
                }
                $end = strpos($src, '*', $i + 1);
                if ($end !== false && $end > $i + 1) {
                    $content = substr($src, $i + 1, $end - $i - 1);
                    $out .= '<em>' . $this->renderInline($content, $depth + 1) . '</em>';
                    $i = $end + 1;
                    continue;
                }
                $out .= self::esc($c);
                $i++;
                continue;
            }

            // Link [text](href). Bracket-balanced so authored text like
            // `[a [b] c](url)` finds the *outer* `]` instead of the first.
            // Same idea for the href's parentheses.
            if ($c === '[') {
                $bracket = self::findBalancedClose($src, $i + 1, $n, '[', ']');
                if ($bracket !== -1 && $bracket + 1 < $n && $src[$bracket + 1] === '(') {
                    $paren = self::findBalancedClose($src, $bracket + 2, $n, '(', ')');
                    if ($paren !== -1) {
                        $text = substr($src, $i + 1, $bracket - $i - 1);
                        $href = substr($src, $bracket + 2, $paren - $bracket - 2);
                        $resolved = $this->resolveHref($href);
                        if ($resolved !== null) {
                            $out .= '<a href="' . self::esc($resolved) . '">'
                                  . $this->renderInline($text, $depth + 1)
                                  . '</a>';
                            $i = $paren + 1;
                            continue;
                        }
                    }
                }
                $out .= self::esc($c);
                $i++;
                continue;
            }

            // Fallthrough: $c is a single byte that didn't match any
            // inline-delimiter (`*`, `\``, `[`). For ASCII bytes (< 0x80)
            // we still need esc() — they might be `<`, `>`, `&`, `"`, `'`.
            // For >= 0x80 bytes (always part of a multi-byte UTF-8
            // sequence here, per the entry-validation around line 82)
            // we pass the byte through raw. Calling esc() on a single
            // sub-character byte would trigger ENT_SUBSTITUTE and
            // corrupt the sequence into U+FFFD replacement chars.
            $out .= ord($c) < 0x80 ? self::esc($c) : $c;
            $i++;
        }
        return $out;
    }

    /**
     * Validate a Markdown-link href and return the resolved URL, or null
     * if the href fails the allowlist. Delegates to the shared
     * `HrefSanitizer` so Markdown links and the `{% safe_href %}`
     * template directive use the exact same rule set — one allowlist,
     * two call sites, no drift possible.
     *
     * Markdown body content can carry unresolved path markers (`~/…`
     * resolves to langRoot, `^/…` to basePath); PageLoader resolves
     * markers in attribute values, but body text only reaches this
     * renderer here. So we use `HrefSanitizer::resolve()` rather than
     * `check()` — same allowlist, but with marker expansion first.
     *
     * Behaviour vs. the previous in-class implementation:
     *
     *   - The `@`-in-https check used to reject `@` anywhere in the
     *     URL; the shared sanitizer only rejects `@` in the authority
     *     (between scheme and first /?#). Legitimate query strings
     *     like `?email=foo@bar.com` are now accepted, the userinfo-
     *     form phishing case (`https://user:pass@evil/`) is still
     *     blocked.
     *
     *   - Scheme-relative `//host` and Windows-style `/\host` are now
     *     explicitly rejected (defence-in-depth — the previous code
     *     would have passed `//host` as a `starts-with-/` value, which
     *     a browser would resolve as a cross-origin link).
     *
     *   - Backslash anywhere in the href is rejected (URL-parser-
     *     confusion defence).
     */
    private function resolveHref(string $href): ?string
    {
        return HrefSanitizer::resolve($href, $this->langRoot, $this->basePath);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Find the matching close character starting from $from, balancing
     * any nested $open occurrences. Returns the index of the matching
     * close, or -1 if no balanced close exists within $n.
     *
     * Used by the link parser so `[a [b] c](href)` and `[label](url(x))`
     * resolve to the OUTER delimiters instead of the first one strpos
     * would find. Bounded by a depth cap to keep pathological input from
     * producing surprising parses.
     */
    private static function findBalancedClose(string $src, int $from, int $n, string $open, string $close): int
    {
        $depth = 1;
        for ($i = $from; $i < $n; $i++) {
            $c = $src[$i];
            if ($c === $open) {
                $depth++;
                continue;
            }
            if ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }
}
