<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Raised when a content file fails to parse. Carries the source line
 * number so error pages / logs can point a maintainer at the offending
 * row directly. The line is 1-based and refers to the position in the
 * original .md file (offsets from sub-parsers are translated upstream).
 */
final class ParseException extends \RuntimeException
{
    /**
     * Source line in the .md file where the offending construct sits.
     * Named `sourceLine` rather than `line` because `\Exception::$line`
     * already exists as a non-readonly property — PHP forbids changing
     * the inherited mutability, so reusing the name is a fatal error.
     */
    public readonly int $sourceLine;

    public function __construct(string $message, int $sourceLine)
    {
        $this->sourceLine = $sourceLine;
        parent::__construct(sprintf('Line %d: %s', $sourceLine, $message));
    }
}
