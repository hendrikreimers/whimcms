<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

/**
 * Expression sub-language used inside directives.
 *
 * Supported value forms:
 *   "literal"     — string literal (single or double quoted)
 *   42            — integer
 *   3.14          — float
 *   true / false  — booleans
 *   null          — null
 *   %path.to.x%   — context lookup, escaped at output
 *   %%path%%      — same lookup, raw (only meaningful at output sites)
 *   { k: v, … }   — object literal; values are themselves expressions
 *   [ a, b, … ]   — array literal
 *
 * Conditions add ==, !=, &&, ||, ! on top of the above.
 *
 * Top-level argument parsing is delimiter-aware: it skips strings, %…%,
 * and balanced brackets so commas inside literals don't split the
 * argument list.
 */
final class Expression
{
    /**
     * Evaluate a value expression against a context.
     *
     * @param array<string, mixed> $ctx
     */
    public static function evaluate(string $expr, array $ctx): mixed
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        $first = $expr[0];
        $last  = $expr[strlen($expr) - 1];

        // Quoted string literal.
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            return self::unescapeString(substr($expr, 1, -1));
        }
        if (is_numeric($expr)) {
            return $expr + 0;
        }
        if ($expr === 'true')  { return true; }
        if ($expr === 'false') { return false; }
        if ($expr === 'null')  { return null; }

        // Raw lookup form (the same path; raw vs escaped only matters at output).
        if (str_starts_with($expr, '%%') && str_ends_with($expr, '%%')) {
            return self::lookup(trim(substr($expr, 2, -2)), $ctx);
        }
        if ($first === '%' && $last === '%') {
            return self::lookup(trim(substr($expr, 1, -1)), $ctx);
        }

        // Object literal.
        if ($first === '{' && $last === '}') {
            $args = self::parseArgs(substr($expr, 1, -1));
            $obj = [];
            foreach ($args as $k => $v) {
                $obj[$k] = self::evaluate($v, $ctx);
            }
            return $obj;
        }

        // Array literal.
        if ($first === '[' && $last === ']') {
            $items = self::splitTopLevel(substr($expr, 1, -1), ',');
            $arr = [];
            foreach ($items as $it) {
                $it = trim($it);
                if ($it !== '') {
                    $arr[] = self::evaluate($it, $ctx);
                }
            }
            return $arr;
        }

        // Bare identifier — treated as a dot-path lookup.
        return self::lookup($expr, $ctx);
    }

    /**
     * Evaluate a boolean condition. Operator precedence: ! > == != > && > ||.
     *
     * @param array<string, mixed> $ctx
     */
    public static function evaluateCondition(string $expr, array $ctx): mixed
    {
        $expr = trim($expr);
        $orParts = self::splitTopLevel($expr, '||');
        if (count($orParts) > 1) {
            foreach ($orParts as $p) {
                if (self::truthy(self::evalAnd($p, $ctx))) {
                    return true;
                }
            }
            return false;
        }
        return self::evalAnd($expr, $ctx);
    }

    /** @param array<string, mixed> $ctx */
    private static function evalAnd(string $expr, array $ctx): mixed
    {
        $parts = self::splitTopLevel($expr, '&&');
        if (count($parts) > 1) {
            foreach ($parts as $p) {
                if (!self::truthy(self::evalCmp($p, $ctx))) {
                    return false;
                }
            }
            return true;
        }
        return self::evalCmp($expr, $ctx);
    }

    /** @param array<string, mixed> $ctx */
    private static function evalCmp(string $expr, array $ctx): mixed
    {
        $expr = trim($expr);
        foreach (['==', '!='] as $op) {
            $parts = self::splitTopLevel($expr, $op);
            if (count($parts) === 2) {
                $a = self::evaluate(trim($parts[0]), $ctx);
                $b = self::evaluate(trim($parts[1]), $ctx);
                return $op === '==' ? $a == $b : $a != $b;
            }
        }
        if (str_starts_with($expr, '!')) {
            return !self::truthy(self::evaluate(substr($expr, 1), $ctx));
        }
        return self::evaluate($expr, $ctx);
    }

    /**
     * Truthy semantics for the template's eyes:
     *   - null is false
     *   - empty array is false, non-empty is true
     *   - everything else as PHP normally treats it
     */
    public static function truthy(mixed $v): bool
    {
        if (is_array($v)) {
            return count($v) > 0;
        }
        return (bool)$v;
    }

    /**
     * Resolve "a.b.c" against an array context. Returns null on any miss.
     *
     * @param array<string, mixed> $ctx
     */
    public static function lookup(string $path, array $ctx): mixed
    {
        if ($path === '') {
            return null;
        }
        $cur = $ctx;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
                continue;
            }
            if (is_object($cur) && isset($cur->$part)) {
                $cur = $cur->$part;
                continue;
            }
            return null;
        }
        return $cur;
    }

    /**
     * Parse "key: value, key: value" into an associative array of raw
     * value expression strings. Values are evaluated lazily by the caller.
     *
     * @return array<string, string>
     */
    public static function parseArgs(string $body): array
    {
        $out = [];
        $i = 0;
        $n = strlen($body);
        while ($i < $n) {
            self::skipSpace($body, $i, $n);
            if ($i >= $n) {
                break;
            }
            $keyStart = $i;
            while ($i < $n && (ctype_alnum($body[$i]) || $body[$i] === '_')) {
                $i++;
            }
            if ($i === $keyStart) {
                throw new \RuntimeException("Expected key in: {$body}");
            }
            $key = substr($body, $keyStart, $i - $keyStart);
            self::skipSpace($body, $i, $n);
            if ($i >= $n || $body[$i] !== ':') {
                throw new \RuntimeException("Expected ':' after '{$key}' in: {$body}");
            }
            $i++;
            self::skipSpace($body, $i, $n);
            $valStart = $i;
            $depth = 0;
            while ($i < $n) {
                $c = $body[$i];
                if ($c === '"' || $c === "'") {
                    $i = self::skipString($body, $i, $n);
                    continue;
                }
                if ($c === '%') {
                    $i = self::skipPercent($body, $i, $n);
                    continue;
                }
                if ($c === '{' || $c === '[' || $c === '(') {
                    $depth++;
                    $i++;
                    continue;
                }
                if ($c === '}' || $c === ']' || $c === ')') {
                    $depth--;
                    $i++;
                    continue;
                }
                if ($c === ',' && $depth === 0) {
                    break;
                }
                $i++;
            }
            $out[$key] = trim(substr($body, $valStart, $i - $valStart));
            if ($i < $n && $body[$i] === ',') {
                $i++;
            }
        }
        return $out;
    }

    /**
     * Split a string on a delimiter at top-level depth, ignoring delimiters
     * inside strings, %…%, and balanced brackets.
     *
     * @return list<string>
     */
    public static function splitTopLevel(string $s, string $delim): array
    {
        $out = [];
        $i = 0;
        $n = strlen($s);
        $start = 0;
        $depth = 0;
        $dlen  = strlen($delim);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '"' || $c === "'") {
                $i = self::skipString($s, $i, $n);
                continue;
            }
            if ($c === '%') {
                $i = self::skipPercent($s, $i, $n);
                continue;
            }
            if ($c === '{' || $c === '[' || $c === '(') {
                $depth++;
                $i++;
                continue;
            }
            if ($c === '}' || $c === ']' || $c === ')') {
                $depth--;
                $i++;
                continue;
            }
            if ($depth === 0 && $i + $dlen <= $n && substr($s, $i, $dlen) === $delim) {
                $out[] = substr($s, $start, $i - $start);
                $i += $dlen;
                $start = $i;
                continue;
            }
            $i++;
        }
        $out[] = substr($s, $start);
        return $out;
    }

    /** Strip surrounding matched quotes from a value-expression-like string. */
    public static function stripQuotes(string $s): string
    {
        $s = trim($s);
        if (strlen($s) >= 2) {
            $f = $s[0];
            $l = $s[strlen($s) - 1];
            if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                return self::unescapeString(substr($s, 1, -1));
            }
        }
        return $s;
    }

    /** Decode the small set of escapes string literals support. */
    public static function unescapeString(string $s): string
    {
        return str_replace(
            ['\\\\', "\\'", '\\"', '\\n', '\\t'],
            ['\\', "'", '"', "\n", "\t"],
            $s
        );
    }

    private static function skipSpace(string $s, int &$i, int $n): void
    {
        while ($i < $n && ctype_space($s[$i])) {
            $i++;
        }
    }

    private static function skipString(string $s, int $i, int $n): int
    {
        $q = $s[$i];
        $i++;
        while ($i < $n && $s[$i] !== $q) {
            if ($s[$i] === '\\' && $i + 1 < $n) {
                $i++;
            }
            $i++;
        }
        return $i + 1;
    }

    private static function skipPercent(string $s, int $i, int $n): int
    {
        if ($i + 1 < $n && $s[$i + 1] === '%') {
            $end = strpos($s, '%%', $i + 2);
            return $end !== false ? $end + 2 : $n;
        }
        $end = strpos($s, '%', $i + 1);
        return $end !== false ? $end + 1 : $n;
    }
}
