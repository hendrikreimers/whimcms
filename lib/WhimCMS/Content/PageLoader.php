<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Loads, parses, validates, renders, and caches a page's content file.
 *
 * Pipeline (cold path — cache miss):
 *
 *   1. Validate `lang` and `slug` against tight regexes — no candidate
 *      that fails the patterns ever touches the filesystem.
 *   2. Build the candidate path under `contentDir/<lang>/<slug>.md` and
 *      verify with realpath that it lives strictly under contentDir.
 *      This is the same defense-in-depth pattern as I18n::load and the
 *      template engine: regex first, realpath second.
 *   3. Read the file, enforce a hard byte ceiling.
 *   4. Split off the optional `---`-delimited front-matter and parse it
 *      via AttributeParser.
 *   5. Walk the block stream `::: <type> … :::`, parse each block's
 *      attributes via AttributeParser, separate optional Markdown body
 *      via the `---` separator inside the block.
 *   6. Validate each block against the BlockRegistry's per-type schema:
 *      unknown types fail loud, missing required attributes fail loud,
 *      unexpected attributes fail loud.
 *   7. Resolve path markers (~/…, ^/…) on every string value in every
 *      block's attribute tree, exactly like I18n::resolvePaths.
 *   8. Render each block's Markdown body through the Safe-Subset Markdown
 *      renderer, which also resolves path markers in link hrefs.
 *   9. Validate header (whitelist of allowed top-level keys; layout must
 *      be in the configured allowlist).
 *  10. Write the result to the on-disk cache as a HMAC-signed JSON file
 *      (extension `.cache`, never executable). See cache-format note below.
 *
 * Hot path (cache hit): read the cache file, verify HMAC against the
 * application secret, json_decode, check the recorded source mtime
 * against the live file, return the reconstructed Page if they match.
 *
 * Cache format
 * ------------
 * On disk: `<hex-hmac>\n<json-payload>` in a file with extension `.cache`.
 *
 * Why not `<?php return [...]` via var_export (which is what TYPO3 /
 * Symfony / Laravel use for their compiled caches): the var_export +
 * include pattern is fast and benefits from OPcache, but if any other
 * vulnerability ever gives an attacker write access to var/cache/, they
 * can plant arbitrary PHP that gets executed on the next include — RCE.
 * The JSON-with-HMAC format closes that path:
 *
 *   - The file extension is `.cache`, never executed by Apache/PHP.
 *   - Forging a valid cache file requires the application secret in
 *     var/state/secret. Without it, the HMAC check rejects the file
 *     and we fall back to regenerating from source.
 *   - HMAC is verified BEFORE json_decode runs, so a forged payload
 *     never reaches the parser.
 *
 * Failure mode is consistent: any parse / validation / IO problem throws
 * a typed exception. Kernel turns those into either a debug-mode trace
 * page or a generic 500 in production, so a malformed content file can
 * never produce a half-rendered page that ships partial content to a
 * visitor.
 */
final class PageLoader
{
    /** Hard ceiling on a single .md file. Configurable by caller; default 256 KiB. */
    private int $maxBytes;

    /** @var list<string> */
    private array $allowedLayouts;

    private string $contentDir;
    private string $contentRealDir;
    private string $cacheDir;
    private BlockRegistry $registry;
    private ?CacheSweeper $sweeper;

    // Identifier + structural patterns are sourced from Identifiers
    // (single source of truth, shared with the admin-side parsers).
    // Slugs are author-controlled (defined in config/routes.php), not
    // visitor-controlled. The realpath-containment check below is the
    // second gate regardless.
    private const HEADER_ALLOWED_KEYS = ['layout', 'meta', 'hidden', 'disabled'];
    private const META_ALLOWED_KEYS = ['title', 'description'];

    /**
     * Accepted string forms for the boolean front-matter flags
     * (`hidden`, `disabled`). AttributeParser produces strings — the
     * loader normalises here and rejects anything else.
     */
    private const BOOL_TRUE_FORMS  = ['true',  'yes', '1'];
    private const BOOL_FALSE_FORMS = ['false', 'no',  '0', ''];

    /** Application secret used to HMAC-sign cache files. */
    private string $secret;

    /**
     * Outcome of the last load() call, in cache-state vocabulary:
     *
     *   'hit'           — served from a verified cache file
     *   'miss'          — cold path, parse+render, cache file written
     *   'write-failed'  — cold path, parse+render, cache write failed
     *                     (page still rendered correctly; on next request
     *                     the cold path runs again)
     *   null            — load() never completed (early return / throw)
     *
     * Set on every load() entry (reset to null) so a stale value from a
     * previous call cannot leak into the next request's diagnostics.
     * Surfaced via the X-H42-Cache response header when debug mode is on
     * — see Kernel::renderPage.
     */
    private ?string $lastStatus = null;

    public function lastStatus(): ?string
    {
        return $this->lastStatus;
    }

    /**
     * @param list<string> $allowedLayouts
     *
     * The constructor deliberately tolerates a missing content directory:
     * on a fresh deploy or a partial rollout, `content/` may not exist yet.
     * In that case every `load()` call short-circuits to ContentNotFoundException
     * so the legacy per-page-template flow handles the request — boot must
     * not fail just because no page has been migrated yet.
     *
     * `$secret` is the application secret (loaded by the Kernel via
     * Secret::load) used to sign cache payloads so a planted cache file
     * without the secret cannot be loaded as-if-trusted. Pass an empty
     * string only in unit tests; production callers always have a secret.
     */
    public function __construct(
        string $contentDir,
        string $cacheDir,
        BlockRegistry $registry,
        string $secret,
        int $maxBytes = 262144,
        array $allowedLayouts = ['default'],
        ?CacheSweeper $sweeper = null,
    ) {
        $real = realpath($contentDir);
        $this->contentDir     = rtrim($contentDir, '/\\');
        $this->contentRealDir = $real === false ? '' : $real;
        $this->cacheDir       = rtrim($cacheDir, '/\\');
        $this->registry       = $registry;
        $this->secret         = $secret;
        $this->maxBytes       = max(1024, $maxBytes);
        $this->allowedLayouts = array_values(array_unique($allowedLayouts));
        if ($this->allowedLayouts === []) {
            $this->allowedLayouts = ['default'];
        }
        $this->sweeper = $sweeper;
    }

    /**
     * Resolve, parse, validate, and render the content for one page.
     *
     * The same caching key inputs (lang, slug, basePath, singleLang) the
     * I18n loader uses today: any change to base or single/multi-lang
     * mode invalidates the cached output because path-marker resolution
     * is baked into it.
     */
    public function load(string $lang, string $slug, string $basePath, bool $singleLang): Page
    {
        $this->lastStatus = null;
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
        if ($this->contentRealDir === '') {
            // Content directory does not exist on this deployment — every
            // page falls through to the legacy template flow.
            throw new ContentNotFoundException("No content directory; {$lang}/{$slug}.md unreachable.");
        }

        $sourcePath = $this->contentDir . '/' . $lang . '/' . $slug . '.md';
        $real       = realpath($sourcePath);
        if ($real === false) {
            throw new ContentNotFoundException("Content file not found: {$lang}/{$slug}.md");
        }
        if (!str_starts_with($real, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Content path escapes root: {$lang}/{$slug}");
        }

        $mtime = @filemtime($real);
        if ($mtime === false) {
            throw new \RuntimeException("Cannot stat content file: {$lang}/{$slug}.md");
        }
        $langRoot = $singleLang ? $basePath : ($basePath . '/' . $lang);

        $cacheKey  = hash('sha256', $real . '|' . $basePath . '|' . $langRoot . '|' . ($singleLang ? '1' : '0'));
        $cachePath = $this->cacheDir . '/' . $cacheKey . '.cache';

        $cached = $this->loadFromCache($cachePath, $mtime);
        if ($cached !== null) {
            $this->lastStatus = 'hit';
            return $cached;
        }

        $size = @filesize($real);
        if ($size === false) {
            throw new \RuntimeException("Cannot stat content file: {$lang}/{$slug}.md");
        }
        if ($size > $this->maxBytes) {
            throw new \RuntimeException(
                'Content file exceeds maximum size of ' . $this->maxBytes . ' bytes: ' . $lang . '/' . $slug . '.md'
            );
        }
        $src = @file_get_contents($real);
        if ($src === false) {
            throw new \RuntimeException("Cannot read content file: {$lang}/{$slug}.md");
        }
        // Hard-fail on non-UTF-8 input. The downstream sanitizer would
        // silently substitute mangled bytes with U+FFFD via ENT_SUBSTITUTE,
        // which masks an authoring error (editor saved as Latin-1 /
        // Windows-1252) until a visitor sees garbled text. Loud and early
        // is the right place to surface this.
        if (preg_match('//u', $src) !== 1) {
            throw new ParseException(
                "Content file is not valid UTF-8 — re-save '{$lang}/{$slug}.md' as UTF-8 (without BOM).",
                1
            );
        }

        $page = $this->parseAndRender($src, $langRoot, $basePath);
        $writeOk = $this->writeCache($cachePath, $page, $mtime, $real);
        $this->lastStatus = $writeOk ? 'miss' : 'write-failed';
        // Best-effort cache cleanup — runs at most once per configured
        // interval (sentinel-gated inside the sweeper). Triggered only
        // on cache-miss writes so cache hits stay fast.
        $this->sweeper?->sweepIfDue();
        return $page;
    }

    /**
     * Cache helper: best-effort load of a previously-rendered page.
     *
     * Read the file as raw bytes, split off the HMAC, verify it against
     * the JSON payload using the application secret, then json_decode.
     * Any malformed cache file (bad HMAC, bad JSON, wrong shape, mtime
     * mismatch, IO error) is treated as a miss and quietly regenerated.
     *
     * Crucially, the file is NEVER `include`d. A cache file with the
     * wrong HMAC is dropped before its contents reach any parser, so a
     * planted file (from any hypothetical write-primitive elsewhere in
     * the stack) cannot deliver code execution.
     */
    private function loadFromCache(string $cachePath, int $sourceMtime): ?Page
    {
        $raw = @file_get_contents($cachePath);
        if ($raw === false) {
            return null;
        }
        $data = self::verifyAndDecode($raw, $this->secret);
        if ($data === null) {
            return null;
        }
        if (($data['mtime'] ?? null) !== $sourceMtime) {
            return null;
        }
        $header = is_array($data['header'] ?? null) ? $data['header'] : [];
        $blocks = [];
        foreach (($data['blocks'] ?? []) as $b) {
            if (!is_array($b)) {
                return null;
            }
            $type = is_string($b['type'] ?? null) ? $b['type'] : null;
            $attrs = is_array($b['attrs'] ?? null) ? $b['attrs'] : null;
            $body  = is_string($b['body']  ?? null) ? $b['body']  : null;
            if ($type === null || $attrs === null || $body === null) {
                return null;
            }
            $blocks[] = new Block($type, $attrs, $body);
        }
        return new Page($header, $blocks);
    }

    /**
     * Cache writer: serialise the parsed page to a HMAC-signed JSON file.
     * We write via a temp file + atomic rename so a partial write can't
     * poison readers. Any failure here is logged-and-ignored; the next
     * request will simply re-render. Cache writes are best-effort.
     *
     * `source` is recorded in the payload so CacheSweeper can later
     * decide whether the cache file is orphaned (source deleted/renamed).
     * It is read by the sweeper exclusively via `is_file()`; the value
     * is never used as a path argument to a write or delete sink, so a
     * forged `source` cannot cause anything outside the cache dir to
     * be touched.
     */
    private function writeCache(string $cachePath, Page $page, int $sourceMtime, string $sourcePath): bool
    {
        $blockData = [];
        foreach ($page->blocks as $b) {
            $blockData[] = ['type' => $b->type, 'attrs' => $b->attrs, 'body' => $b->body];
        }
        $payload = [
            'mtime'  => $sourceMtime,
            'source' => $sourcePath,
            'header' => $page->header,
            'blocks' => $blockData,
        ];
        $bytes = self::encodeAndSign($payload, $this->secret);
        if ($bytes === null) {
            return false;
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o700, true);
        }
        $tmp = $cachePath . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $cachePath)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Encode a payload as JSON and prepend a HMAC over the JSON bytes.
     * On-disk format: `<hex-hmac>\n<json-payload>`.
     *
     * Returns null if json_encode fails (e.g. unsupported types) — caller
     * silently aborts the cache write; the next request will retry.
     *
     * @param array<string, mixed> $payload
     */
    public static function encodeAndSign(array $payload, string $secret): ?string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json)) {
            return null;
        }
        $hmac = hash_hmac('sha256', $json, $secret);
        return $hmac . "\n" . $json;
    }

    /**
     * Inverse of encodeAndSign(). Returns the decoded payload only if
     * the HMAC verifies and the bytes parse as a JSON object. Returns
     * null on any verification or parse failure — callers treat that as
     * a cache miss.
     *
     * Constant-time HMAC comparison via hash_equals so signature bytes
     * don't leak through timing. Format check is strict: exactly one
     * newline separator, hex HMAC of the right length, JSON object root.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyAndDecode(string $raw, string $secret): ?array
    {
        $nl = strpos($raw, "\n");
        if ($nl === false) {
            return null;
        }
        $providedHmac = substr($raw, 0, $nl);
        $json         = substr($raw, $nl + 1);
        // SHA-256 hex is exactly 64 chars. Reject anything else before
        // hash_equals — keeps the constant-time compare from operating
        // on absurd inputs.
        if (strlen($providedHmac) !== 64 || preg_match('/^[a-f0-9]{64}$/', $providedHmac) !== 1) {
            return null;
        }
        $expected = hash_hmac('sha256', $json, $secret);
        if (!hash_equals($expected, $providedHmac)) {
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Front-matter + block stream → Page. Path-marker resolution and
     * Markdown rendering are applied per-block here, before the result
     * is handed back for caching.
     */
    private function parseAndRender(string $src, string $langRoot, string $basePath): Page
    {
        $src = $this->normaliseLineEndings($src);
        if (strpos($src, "\0") !== false) {
            throw new ParseException('Content file contains a null byte.', 1);
        }
        $lines = explode("\n", $src);
        $n     = count($lines);
        $i     = 0;

        $header = [];
        if ($n > 0 && $lines[0] === '---') {
            $startFm = 1;
            $j = 1;
            while ($j < $n && $lines[$j] !== '---') {
                $j++;
            }
            if ($j >= $n) {
                throw new ParseException('Unclosed front-matter (no closing "---" found).', 1);
            }
            $fmSrc = implode("\n", array_slice($lines, $startFm, $j - $startFm));
            $header = AttributeParser::parse($fmSrc, $startFm + 1);
            $i = $j + 1;
        }
        $this->validateHeader($header);

        $markdown = new Markdown($langRoot, $basePath);
        $blocks   = [];

        while ($i < $n) {
            // Skip blank lines between blocks.
            while ($i < $n && trim($lines[$i]) === '') {
                $i++;
            }
            if ($i >= $n) {
                break;
            }

            $openLine = $i + 1;
            if (preg_match(Identifiers::BLOCK_OPEN_PATTERN, $lines[$i], $m) !== 1) {
                throw new ParseException(
                    'Expected block opener "::: <type>", got: ' . self::quoteForError($lines[$i]),
                    $openLine
                );
            }
            $type = $m[1];
            $i++;

            $attrStart = $i;
            $attrEnd   = null;
            $bodyStart = null;
            $bodyEnd   = null;
            while ($i < $n) {
                $line = $lines[$i];
                if ($line === ':::') {
                    if ($attrEnd === null) {
                        $attrEnd = $i;
                    } else {
                        $bodyEnd = $i;
                    }
                    $i++;
                    break;
                }
                if ($line === '---' && $attrEnd === null) {
                    $attrEnd   = $i;
                    $bodyStart = $i + 1;
                    $i++;
                    continue;
                }
                $i++;
            }
            if ($attrEnd === null) {
                throw new ParseException("Unclosed block opened with '::: {$type}'.", $openLine);
            }
            if ($bodyStart !== null && $bodyEnd === null) {
                throw new ParseException("Unclosed block opened with '::: {$type}' (body missing close).", $openLine);
            }

            $attrSrc = implode("\n", array_slice($lines, $attrStart, $attrEnd - $attrStart));
            $attrs   = AttributeParser::parse($attrSrc, $attrStart + 1);

            $this->registry->validate($type, $attrs, $openLine);
            $this->resolvePaths($attrs, $langRoot, $basePath);

            $bodyHtml = '';
            if ($bodyStart !== null && $bodyEnd !== null && $bodyEnd > $bodyStart) {
                $bodySrc  = implode("\n", array_slice($lines, $bodyStart, $bodyEnd - $bodyStart));
                $bodyHtml = $markdown->render($bodySrc);
            }

            $blocks[] = new Block($type, $attrs, $bodyHtml);
        }

        return new Page($header, $blocks);
    }

    /**
     * Validate the page front-matter: only the documented top-level keys
     * are accepted; `meta` may contain only `title` and `description`;
     * `layout` must be present in the configured allowlist.
     *
     * @param array<string, mixed> $header
     */
    private function validateHeader(array $header): void
    {
        $allowed = array_flip(self::HEADER_ALLOWED_KEYS);
        foreach (array_keys($header) as $k) {
            if (!is_string($k) || !isset($allowed[$k])) {
                throw new ParseException(
                    "Unexpected front-matter key '" . (string)$k . "'.",
                    1
                );
            }
        }
        if (isset($header['layout'])) {
            $layout = $header['layout'];
            if (!is_string($layout) || !in_array($layout, $this->allowedLayouts, true)) {
                throw new ParseException(
                    "Layout '" . (is_string($layout) ? $layout : 'non-string') . "' is not in the allowlist.",
                    1
                );
            }
        }
        foreach (['hidden', 'disabled'] as $boolKey) {
            if (!isset($header[$boolKey])) continue;
            $v = $header[$boolKey];
            if (!is_string($v)) {
                throw new ParseException("Front-matter '{$boolKey}' must be a string.", 1);
            }
            $norm = strtolower(trim($v));
            if (!in_array($norm, self::BOOL_TRUE_FORMS,  true)
                && !in_array($norm, self::BOOL_FALSE_FORMS, true)) {
                throw new ParseException(
                    "Front-matter '{$boolKey}' must be true/false/yes/no/1/0.",
                    1
                );
            }
        }
        if (isset($header['meta'])) {
            $meta = $header['meta'];
            if (!is_array($meta)) {
                throw new ParseException("Front-matter 'meta' must be a map.", 1);
            }
            $allowedMeta = array_flip(self::META_ALLOWED_KEYS);
            foreach (array_keys($meta) as $k) {
                if (!is_string($k) || !isset($allowedMeta[$k])) {
                    throw new ParseException("Unexpected meta key '" . (string)$k . "'.", 1);
                }
            }
        }
    }

    /**
     * Recursively rewrite path-marker prefixes in string values of an
     * attribute tree. Mirrors I18n::resolvePaths so behaviour is identical
     * across i18n strings and content attributes.
     *
     * @param array<int|string, mixed> $node
     */
    private function resolvePaths(array &$node, string $langRoot, string $basePath): void
    {
        foreach ($node as &$v) {
            if (is_array($v)) {
                $this->resolvePaths($v, $langRoot, $basePath);
                continue;
            }
            if (!is_string($v) || $v === '') {
                continue;
            }
            if ($v[0] === '~') {
                $v = $langRoot . substr($v, 1);
            } elseif ($v[0] === '^') {
                $v = $basePath . substr($v, 1);
            }
        }
        unset($v);
    }

    private function normaliseLineEndings(string $src): string
    {
        $src = preg_replace('/^\xEF\xBB\xBF/', '', $src) ?? $src;
        $src = str_replace("\r\n", "\n", $src);
        return str_replace("\r", "\n", $src);
    }

    private static function quoteForError(string $line): string
    {
        // Byte-level truncation is fine for an error message — even if it
        // splits a multibyte char, it's only diagnostic output. Avoiding
        // mb_substr here means the function works on hosts without
        // ext-mbstring enabled, which we should not silently require.
        $trimmed = strlen($line) > 80 ? substr($line, 0, 80) : $line;
        return '"' . str_replace(["\0", "\r", "\n", "\t"], ['', '', '\\n', '\\t'], $trimmed) . '"';
    }
}
