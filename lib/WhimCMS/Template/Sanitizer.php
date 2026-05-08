<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Output-side sanitizers. All template output goes through these.
 *
 * - escape()       : standard HTML-entity encoding for the default %var% mode.
 * - sanitizeEm()   : the %%var%% mode — same encoding, but literal <em>/</em>
 *                    tags survive. No attributes, no other tags. Intended for
 *                    a small handful of i18n strings that highlight a word.
 * - stringify()    : coerces arbitrary scalars to a string for output;
 *                    arrays/objects render as empty strings (deliberate —
 *                    accidentally dumping structures into HTML is rarely
 *                    what we want).
 */
final class Sanitizer
{
    /**
     * HTML-escape a string with quotes and substitute invalid UTF-8.
     */
    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape everything except literal <em>/</em> tags. Safe even if
     * input was attacker-controlled: tags are restored from sentinel
     * markers placed before the escape pass, so any "<em" with attributes,
     * "<EM>", or other variants get fully escaped.
     */
    public static function sanitizeEm(string $s): string
    {
        $open  = "\x01EMOPEN\x01";
        $close = "\x01EMCLOSE\x01";
        $marked = preg_replace('#<em>#i', $open, $s) ?? $s;
        $marked = preg_replace('#</em>#i', $close, $marked) ?? $marked;
        $escaped = self::escape($marked);
        return str_replace([$open, $close], ['<em>', '</em>'], $escaped);
    }

    /**
     * Convert arbitrary template values to a string for output. Arrays
     * and objects deliberately render empty.
     */
    public static function stringify(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v) || is_object($v)) {
            return '';
        }
        return (string)$v;
    }
}
