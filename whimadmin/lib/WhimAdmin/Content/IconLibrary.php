<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

/**
 * Discover the set of available glyph names by reading the theme's
 * `partials/icons/glyph.html` and scraping every `attrs.name == 'X'`
 * branch. Cached per request.
 */
final class IconLibrary
{
    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(private string $glyphPath)
    {
    }

    /** @return list<string> */
    public function names(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!is_file($this->glyphPath)) {
            return $this->cache = [];
        }
        $src = @file_get_contents($this->glyphPath);
        if ($src === false) {
            return $this->cache = [];
        }
        $out = [];
        if (preg_match_all("/attrs\\.name\\s*==\\s*'([a-z][a-z0-9-]{0,40})'/", $src, $m) > 0) {
            foreach ($m[1] as $name) {
                $out[$name] = true;
            }
        }
        $names = array_keys($out);
        sort($names, SORT_STRING);
        return $this->cache = $names;
    }
}
