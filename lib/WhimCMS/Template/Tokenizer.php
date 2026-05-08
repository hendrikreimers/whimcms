<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Lexer: turns template source into a flat list of Tokens.
 *
 * Syntax (Twig-style):
 *
 *   {{ var }}        Escaped variable output. Bare identifier path
 *                    (no surrounding markers needed inside).
 *
 *   {!! var !!}      Raw output, sanitised down to a <em>-only whitelist.
 *                    Used for the handful of i18n strings that highlight
 *                    a word with <em>.
 *
 *   {% directive %}  Directive — first key in the body is the keyword;
 *                    looked up in the keyword → directive map (built by
 *                    the Engine from BuiltInDirectives) and dispatched
 *                    to that directive's tokenize() to produce a Token.
 *                    The Tokenizer itself knows zero specific keywords.
 *
 *   {# comment #}    Stripped from output.
 *
 *   {@ name … @}     Compile-time annotation. Strictly metadata — produces
 *                    no output token. Extracted in a separate pass via
 *                    scanAnnotations() and dispatched by the Engine to
 *                    AnnotationConsumer directives at boot. The fact that
 *                    annotations cannot reach the output stream is a
 *                    structural guarantee, not a sanitisation step.
 *
 * Plain `%`, `{`, `}` characters are treated as literal text — only the
 * specific multi-character openers above start a token. CSS percentages,
 * URL-encoded sequences, and stray braces in content all survive intact.
 *
 * The directive parser is injected as a Closure so the Tokenizer stays
 * decoupled from the Directive interface and the keyword set. Adding a
 * new directive does not require any change in this file.
 */
final class Tokenizer
{
    /**
     * @param \Closure(string $body): Token $directiveParser
     *        Called for every {% body %} occurrence; returns the token to
     *        emit. The Engine wires this to its keyword → directive map.
     */
    public function __construct(private \Closure $directiveParser)
    {
    }

    /**
     * @return list<Token>
     */
    public function tokenize(string $src): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($src);
        $textStart = 0;

        while ($i < $n) {
            if ($src[$i] !== '{' || $i + 1 >= $n) {
                $i++;
                continue;
            }
            $next = $src[$i + 1];

            // {# comment #}
            if ($next === '#') {
                $this->flushText($tokens, $src, $textStart, $i);
                $end = strpos($src, '#}', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException("Unclosed {# at offset {$i}");
                }
                $i = $end + 2;
                $textStart = $i;
                continue;
            }

            // {@ annotation @} — silently skipped here; harvested separately
            // via scanAnnotations() at engine boot.
            if ($next === '@') {
                $this->flushText($tokens, $src, $textStart, $i);
                $end = strpos($src, '@}', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException("Unclosed {@ at offset {$i}");
                }
                $i = $end + 2;
                $textStart = $i;
                continue;
            }

            // {% directive %}
            if ($next === '%') {
                $this->flushText($tokens, $src, $textStart, $i);
                $end = strpos($src, '%}', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException("Unclosed {% at offset {$i}");
                }
                $body = trim(substr($src, $i + 2, $end - $i - 2));
                $tokens[] = ($this->directiveParser)($body);
                $i = $end + 2;
                $textStart = $i;
                continue;
            }

            // {!! raw !!}
            if ($next === '!' && $i + 2 < $n && $src[$i + 2] === '!') {
                $this->flushText($tokens, $src, $textStart, $i);
                $end = strpos($src, '!!}', $i + 3);
                if ($end === false) {
                    throw new \RuntimeException("Unclosed {!! at offset {$i}");
                }
                $expr = trim(substr($src, $i + 3, $end - $i - 3));
                $tokens[] = new Token('raw', ['expr' => $expr]);
                $i = $end + 3;
                $textStart = $i;
                continue;
            }

            // {{ var }}
            if ($next === '{') {
                $this->flushText($tokens, $src, $textStart, $i);
                $end = strpos($src, '}}', $i + 2);
                if ($end === false) {
                    throw new \RuntimeException("Unclosed {{ at offset {$i}");
                }
                $expr = trim(substr($src, $i + 2, $end - $i - 2));
                $tokens[] = new Token('var', ['expr' => $expr]);
                $i = $end + 2;
                $textStart = $i;
                continue;
            }

            // bare `{` — just text, advance one char.
            $i++;
        }

        $this->flushText($tokens, $src, $textStart, $n);
        return $tokens;
    }

    /**
     * Walk the source and return every `{@ … @}` annotation as a parsed
     * Annotation. Used by the Engine's boot-time scan to harvest metadata
     * for AnnotationConsumer directives.
     *
     * Independent of tokenize() so:
     *   - annotation extraction cannot accidentally inject output tokens;
     *   - the eager-scan path can run on files we never tokenise normally
     *     (e.g. a partial that's only included transitively might still
     *     need its annotations registered if something points at it).
     *
     * Line numbers are tracked here so Annotation::parse can produce
     * line-accurate ParseExceptions when a body is malformed.
     *
     * @return list<Annotation>
     */
    public function scanAnnotations(string $src): array
    {
        $out  = [];
        $i    = 0;
        $n    = strlen($src);
        $line = 1;

        while ($i < $n) {
            // The marker we're hunting is always exactly `{@`. Anything
            // else, including other markers like `{# … #}`, is irrelevant
            // to the scan — we don't need to balance them.
            if ($src[$i] === "\n") {
                $line++;
                $i++;
                continue;
            }
            if ($src[$i] !== '{' || $i + 1 >= $n || $src[$i + 1] !== '@') {
                $i++;
                continue;
            }

            $end = strpos($src, '@}', $i + 2);
            if ($end === false) {
                throw new \RuntimeException("Unclosed {@ at line {$line}");
            }
            $body = substr($src, $i + 2, $end - $i - 2);

            // Body's first line starts on the line after the `{@`-line if
            // the opener has a trailing newline; on the same line otherwise.
            // Annotation::parse skips leading blank lines, so a small offset
            // mismatch is harmless — we still get the right line for any
            // genuine error inside the body.
            $bodyStartLine = $line;

            $out[] = Annotation::parse($body, $bodyStartLine);

            // Advance line counter past the consumed bytes.
            $consumed = substr($src, $i, $end + 2 - $i);
            $line += substr_count($consumed, "\n");
            $i = $end + 2;
        }

        return $out;
    }

    /**
     * @param list<Token> $tokens
     */
    private function flushText(array &$tokens, string $src, int $start, int $end): void
    {
        if ($start < $end) {
            $tokens[] = new Token('text', ['value' => substr($src, $start, $end - $start)]);
        }
    }
}
