<?php
declare(strict_types=1);

namespace H42\WhimCMS\Seo;

use H42\WhimCMS\Config;

/**
 * Dynamic /robots.txt response.
 *
 * Driven by `seo.indexable`:
 *   - false → Disallow everything, UNCONDITIONALLY. Categories, override,
 *     and extend files are all ignored — a pre-launch / staging site can
 *     never be re-opened from content/ or a mis-set category. config/ has
 *     priority, full stop.
 *   - true  → Allow all, then emit each configured category block whose
 *     `mode` matches the current SEO/llms state, optionally append an
 *     editor-managed extend file, and advertise the sitemap. A configured
 *     override file replaces the whole generated body.
 *
 * Category emission (config/robots.php → robots.categories[]): each active
 * category emits ONE directive (Allow: / or Disallow: /) for its crawler
 * list. `mode` decides whether — and with which direction — the block
 * appears; `rule` (allow|disallow, default disallow) is the direction it
 * would use:
 *   seo_enabled  → always emitted (indexable); uses `rule` as-is
 *   llms_enabled → always emitted (indexable); uses `rule` when
 *                  seo.llms.enabled is true, else forced to Disallow, so an
 *                  llms-gated allowance never leaks without an llms.txt
 *   disabled     → never emitted (deliberate off switch; its crawlers then
 *                  revert to the global Allow)
 * An unknown `rule` falls back to `disallow`, and an unknown `mode` is
 * forced to `Disallow: /` (fail-safe: a `mode` typo can never silently
 * un-block a category — only the explicit `disabled` opts a block out).
 *
 * Content overrides (both gated on indexable = true; a BARE filename —
 * no path component — or null in config/robots.php; read only from the
 * validated content dir):
 *   robots_override → its verbatim body REPLACES the generated output
 *   robots_extend   → its verbatim body is appended after the category
 *                     blocks, before the Sitemap line
 *
 * Output safety: every config-supplied token (agent name, label) is
 * collapsed to a single line so it cannot inject an extra directive.
 * Override/extend bodies keep their line structure — CR/LF is normalised
 * to LF, other control characters are stripped, and the body is size-
 * capped — because they are line-oriented robots.txt text.
 *
 * Always served as text/plain.
 */
final class Robots
{
    /** Hard cap for an override / extend file body (bytes). */
    private const MAX_OVERLAY_BYTES = 16384;

    /** Valid category `rule` values (config/robots.php); default 'disallow'. */
    private const RULE_ALLOWED = ['disallow', 'allow'];

    public static function send(string $basePath, string $contentDir): void
    {
        $indexable = (bool)Config::get('seo.indexable', false);

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
        }

        // Non-indexable: hard, unconditional disallow. config/ wins;
        // nothing from content/ or the category list can re-open it.
        if (!$indexable) {
            echo "User-agent: *\n";
            echo "Disallow: /\n";
            return;
        }

        // Full manual override (dev opt-in): a filename in
        // robots.robots_override whose file exists replaces the entire
        // generated body, including the sitemap line.
        $overrideName = Config::get('robots.robots_override', null);
        if (is_string($overrideName) && $overrideName !== '') {
            $override = self::readOverlay($contentDir, $overrideName);
            // An empty / whitespace-only override is treated as "capability
            // absent" (fall through to the generated body), so an editor
            // who creates the file but leaves it blank does not silently
            // wipe the whole robots.txt. Mirrors the robots_extend skip.
            if ($override !== null && trim($override) !== '') {
                echo $override;
                if (substr($override, -1) !== "\n") {
                    echo "\n";
                }
                return;
            }
        }

        // Resolve the sitemap origin up front. Past the override early-
        // return we WILL emit a Sitemap line, and Origin::resolve() throws
        // on an unconfigured origin (a real dev state). Resolving here —
        // before any body is echoed — lets that misconfig surface as a
        // clean 500 instead of a truncated 200 (and, under debug, a stack
        // trace bleeding into the body). Matches LlmsTxt, which resolves
        // before echoing.
        $sitemapUrl = Origin::resolve() . $basePath . '/sitemap.xml';

        echo "User-agent: *\n";
        echo "Allow: /\n";

        $llmsEnabled = (bool)Config::get('seo.llms.enabled', false);

        /** @var array<int|string, mixed> $categories */
        $categories = (array)Config::get('robots.categories', []);
        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $mode = is_string($cat['mode'] ?? null) ? $cat['mode'] : '';
            $rule = is_string($cat['rule'] ?? null) ? $cat['rule'] : 'disallow';
            $directive = self::resolveDirective($mode, $rule, $llmsEnabled);
            if ($directive === null) {
                continue;
            }
            $agents = self::cleanList($cat['crawler_list'] ?? null);
            if ($agents === []) {
                continue;
            }
            echo "\n";
            $label = self::oneLine(is_string($cat['label'] ?? null) ? $cat['label'] : '');
            if ($label !== '') {
                echo '# ' . $label . "\n";
            }
            foreach ($agents as $agent) {
                echo 'User-agent: ' . $agent . "\n";
            }
            echo ($directive === 'allow' ? 'Allow' : 'Disallow') . ": /\n";
        }

        // Editor-managed extend file: appended after the category blocks,
        // before the sitemap line.
        $extendName = Config::get('robots.robots_extend', null);
        if (is_string($extendName) && $extendName !== '') {
            $extend = self::readOverlay($contentDir, $extendName);
            if ($extend !== null && trim($extend) !== '') {
                echo "\n" . $extend;
                if (substr($extend, -1) !== "\n") {
                    echo "\n";
                }
            }
        }

        echo "\n";
        echo 'Sitemap: ' . $sitemapUrl . "\n";
    }

    /**
     * Resolve the directive a category emits ('allow' | 'disallow'), or
     * null when the category is not emitted at all. `mode` gates emission
     * and llms-conditionality; `rule` is the base direction (unknown rule
     * → the safe default 'disallow'). Only reached when indexable is true.
     *
     *   seo_enabled  → the rule, as-is
     *   llms_enabled → the rule when llms is enabled, else 'disallow' (an
     *                  llms-gated allowance must not leak without an llms.txt)
     *   disabled     → null (the only opt-out — block omitted)
     *   unknown      → 'disallow' (fail-safe: a `mode` typo keeps blocking
     *                  a category rather than silently un-blocking it)
     */
    private static function resolveDirective(string $mode, string $rule, bool $llmsEnabled): ?string
    {
        if (!in_array($rule, self::RULE_ALLOWED, true)) {
            $rule = 'disallow';
        }
        return match ($mode) {
            'seo_enabled'  => $rule,
            'llms_enabled' => $llmsEnabled ? $rule : 'disallow',
            'disabled'     => null,        // the only opt-out
            default        => 'disallow',  // unknown mode → fail-safe: block
        };
    }

    /**
     * Normalise a crawler list to clean, single-line, non-empty tokens.
     *
     * @param mixed $list
     * @return array<int, string>
     */
    private static function cleanList($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_string($item)) {
                continue;
            }
            $name = self::oneLine($item);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Collapse a value to a single safe line: strip control characters
     * (incl. CR/LF), collapse whitespace runs, trim. Prevents a config
     * token from injecting an extra robots.txt directive.
     */
    private static function oneLine(string $s): string
    {
        $s = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
        $s = (string)preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * Read an editor-managed override / extend file from the content
     * directory. The config value MUST be a bare filename directly in
     * content/ — a value containing a path separator, a leading dot, or a
     * null byte is rejected outright (NOT silently flattened), so a mis-set
     * path can never resolve to a different file. realpath() +
     * str_starts_with() containment is kept as a second gate (symlinks).
     * Returns null on any rejection / miss / unreadable file. The body
     * keeps its line structure: CR/LF normalised to LF, other control
     * characters dropped, and the read is size-capped.
     */
    private static function readOverlay(string $contentDir, string $name): ?string
    {
        // Bare filename only — no path components, no traversal, no dotfiles.
        if ($name === ''
            || $name[0] === '.'
            || strpbrk($name, "/\\") !== false
            || str_contains($name, "\0")) {
            return null;
        }
        $rootReal = realpath($contentDir);
        $real     = realpath($contentDir . '/' . $name);
        if ($real === false || $rootReal === false) {
            return null;
        }
        if (!str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $raw = @file_get_contents($real, false, null, 0, self::MAX_OVERLAY_BYTES);
        if ($raw === false) {
            return null;
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $raw);
    }
}
