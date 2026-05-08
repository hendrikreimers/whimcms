<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * URL/href scheme-allowlist used by both the Markdown link parser and
 * the `{% safe_href %}` template directive.
 *
 * Intended use:
 *
 *   - `check($href)`         — validate a value that has already been
 *                              resolved (path markers `~/...` and `^/...`
 *                              expanded by an earlier pass). Returns the
 *                              input verbatim if valid, null otherwise.
 *                              Used by the template directive: by the
 *                              time the directive runs, PageLoader has
 *                              already resolved markers in attribute
 *                              values.
 *
 *   - `resolve($href, $langRoot, $basePath)` — for callers that still
 *                              hold raw author input (today: only
 *                              Markdown's link parser, which sees
 *                              body-content link hrefs that PageLoader
 *                              never touches): expand `~/...` and
 *                              `^/...`, then run the same allowlist.
 *
 * Allowlist:
 *
 *   - `https://...`     full URL. The authority part (between `https://`
 *                       and the first `/`, `?`, or `#`) must NOT contain
 *                       `@` — that blocks userinfo-form phishing tricks
 *                       (`https://user:pass@evil/`). `@` in the path /
 *                       query / fragment is allowed (legitimate use:
 *                       `?email=foo@bar.com`).
 *
 *   - `mailto:...` / `tel:...`
 *
 *   - `/...`            root-relative path. Scheme-relative `//host` and
 *                       Windows-style `/\host` are explicitly rejected
 *                       (they would let the browser navigate to an
 *                       attacker-chosen origin from a `/`-prefixed href).
 *
 *   - `#...`            in-page anchor.
 *
 *   - `~/...` / `^/...` ONLY via `resolve()`, NEVER via `check()`. A
 *                       raw marker reaching the template layer means a
 *                       resolution step was skipped — we reject it
 *                       loudly rather than emit a literal `~/x` href.
 *
 * Anything else — `http://`, `javascript:`, `data:`, `vbscript:`,
 * `file:`, `ftp:`, scheme-relative `//host`, Windows-style `\\host`,
 * bare hostnames, URL-encoded scheme variants (`javascript%3A...`),
 * HTML-entity variants (`javascript&#58;...`) — is rejected. The
 * scheme match is literal, so encoded variants don't match any
 * allowed prefix and fall through to the final null-return.
 *
 * Defensive bounds enforced before any pattern check:
 *
 *   - Empty / whitespace → null
 *   - Length > 2048 bytes → null
 *   - Any of `\0 \r \n \t SP " < > \` anywhere → null. Control chars
 *     and attribute-breakers, plus backslash (some browsers normalise
 *     `\` to `/` in URL parsing — host-confusion / URL-parser-
 *     differential angles are closed off by rejecting it outright).
 *     Authors who need any of these chars in URLs must percent-encode
 *     them (e.g. space → `%20`).
 */
final class HrefSanitizer
{
    /** Hard ceiling on accepted href length. Generous for real URLs, tight against
     *  attribute-bombing. */
    public const MAX_BYTES = 2048;

    /**
     * Characters that must never appear unencoded in an href. Includes
     * backslash to defend against URL-parser-confusion attacks where the
     * browser normalises `\` to `/` (Windows-path heritage, WHATWG-URL
     * for special schemes).
     */
    private const FORBIDDEN_CHARS = "\0\r\n\t \"<>\\";

    /**
     * Validate a fully-resolved href. Returns the input unchanged if it
     * passes the allowlist, null otherwise. The caller is responsible
     * for HTML-escaping the result if it goes into an attribute.
     */
    public static function check(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (strlen($href) > self::MAX_BYTES) {
            return null;
        }
        if (strpbrk($href, self::FORBIDDEN_CHARS) !== false) {
            return null;
        }

        $first = $href[0];

        // Root-relative path or in-page anchor.
        if ($first === '/' || $first === '#') {
            // Reject scheme-relative `//host` — would let the browser
            // navigate cross-origin from a `/`-prefixed href. (Backslash
            // forms like `/\host` are already caught by FORBIDDEN_CHARS.)
            if ($first === '/' && strlen($href) >= 2 && $href[1] === '/') {
                return null;
            }
            return $href;
        }

        // Path markers must have been resolved before reaching check().
        if ($first === '~' || $first === '^') {
            return null;
        }

        $lower = strtolower($href);

        if (str_starts_with($lower, 'https://')) {
            // Block userinfo-form phishing (`https://user:pass@evil/`).
            // Only the authority part is checked: `@` in the path / query
            // / fragment is allowed for legitimate use (`?email=x@y.com`).
            $afterScheme = substr($href, 8); // length of "https://"
            $authEnd     = strcspn($afterScheme, '/?#');
            $authority   = substr($afterScheme, 0, $authEnd);
            if (strpos($authority, '@') !== false) {
                return null;
            }
            return $href;
        }

        if (str_starts_with($lower, 'mailto:') || str_starts_with($lower, 'tel:')) {
            return $href;
        }

        return null;
    }

    /**
     * Resolve path markers `~/...` (lang-root) and `^/...` (base path)
     * before validating. Used by Markdown's link parser; the template
     * directive does NOT call this because PageLoader has already
     * resolved markers in attribute values.
     *
     * Defensive bounds (length, forbidden chars) run BEFORE marker
     * expansion so a 4 KiB `~/x` cannot bypass the length check by
     * being short pre-expansion.
     */
    public static function resolve(string $href, string $langRoot, string $basePath): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (strlen($href) > self::MAX_BYTES) {
            return null;
        }
        if (strpbrk($href, self::FORBIDDEN_CHARS) !== false) {
            return null;
        }
        // Bare marker with no path is meaningless.
        if ($href === '~' || $href === '^') {
            return null;
        }
        if ($href[0] === '~') {
            return self::check($langRoot . substr($href, 1));
        }
        if ($href[0] === '^') {
            return self::check($basePath . substr($href, 1));
        }
        return self::check($href);
    }
}
