<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Thrown by PageLoader when the content file for a (lang, slug) pair does
 * not exist on disk. The Kernel translates this into a normal 404 render
 * — distinct from "file existed but was malformed", which surfaces as a
 * ParseException and ends up at the 500 path.
 */
final class ContentNotFoundException extends \RuntimeException
{
}
