<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template;

use H42\WhimCMS\Content\BlockRegistry;

/**
 * Template engine entry point.
 *
 * Owns the template directory, the per-request token cache, the directive
 * registry, and the BlockRegistry service. Everything is wired up in the
 * constructor from the single canonical list in BuiltInDirectives — the
 * engine has no external registerDirective() hook because nothing outside
 * needs to inject directives.
 *
 * Self-wiring on boot:
 *
 *   1. Instantiate BlockRegistry (the block-type → schema service).
 *      Populated below from `{@ block @}` annotations in every block
 *      partial; no config file involved.
 *   2. Instantiate every directive listed in BuiltInDirectives::all($this).
 *      The Engine reference is passed so directives can resolve services
 *      (BlocksDirective uses it to reach `$engine->blocks()` at render time).
 *   3. Build three maps from the directive list:
 *        - keyword         → directive    (used by the Tokenizer)
 *        - token-type      → directive    (used by the Renderer)
 *        - annotation-name → consumer     (used by the boot scan below)
 *      Conflicts in any of the three fail loud at boot.
 *   4. Wire the Tokenizer with a body-parser closure that consults the
 *      keyword map and dispatches to the matching directive's tokenize().
 *      The Tokenizer never names a specific directive or keyword.
 *   5. Wire the Renderer with the token-type map.
 *   6. Run the eager annotation scan. For every AnnotationConsumer, walk
 *      its eagerScanPaths() globs, harvest matching `{@ … @}` blocks
 *      from each file via Tokenizer::scanAnnotations(), merge per-file
 *      payloads, and dispatch consumeAnnotation() per (consumer, file).
 *      This is what turns `{@ block required: title @}` in
 *      `partials/blocks/hero.html` into a registered block schema —
 *      no config file involved.
 *
 * Security:
 *   - Template names are validated against [A-Za-z0-9/_-]+ and rejected
 *     if they contain "..".
 *   - The resolved file path is verified to live under $templateDir
 *     via realpath, so even a clever match on the regex can't escape
 *     the templates root.
 *   - The eager-scan glob is rooted under $templateDir; every matched
 *     path is realpath-contained before being read.
 */
final class Engine
{
    private string $templateDir;
    private string $templateRealDir;
    private Renderer $renderer;
    private Tokenizer $tokenizer;
    private BlockRegistry $blocks;

    /** @var array<string, list<Token>> Compiled token cache, keyed by name. */
    private array $compiled = [];

    /** @var array<string, Directive> Keyword → directive, built once at boot. */
    private array $keywordMap = [];

    /**
     * Render-mode flag injected into the context. When present and truthy,
     * `{{ var }}` skips HTML-escape and `{!! raw !!}` skips its em-only
     * sanitiser — both fall back to plain stringification, the right
     * behaviour for plain-text mail bodies. Modelled after Twig's
     * autoescape strategy: the mode is set once on render entry and
     * inherits through every {% include %} since the child render shares
     * the parent's context.
     *
     * Underscore-prefixed name keeps the flag out of normal i18n / form
     * paths (those don't use leading underscores).
     */
    public const TEXT_MODE_FLAG = '__whimcms_text_mode__';

    /**
     * Optional host paths the engine carries through to directives that
     * need them (today: `ImageDirective`, which builds an
     * `AssetPathResolver` and a `CroppedCache` from these). Both are
     * empty strings when the engine is constructed without host
     * context (e.g. unit tests that only render templates against an
     * in-memory context — directives that need paths will throw on
     * first render in that case).
     */
    private string $rootDir;
    private string $varDir;

    public function __construct(string $templateDir, string $rootDir = '', string $varDir = '')
    {
        $real = realpath($templateDir);
        if ($real === false) {
            throw new \RuntimeException("Template directory not found: {$templateDir}");
        }
        $this->templateDir     = rtrim($templateDir, '/\\');
        $this->templateRealDir = $real;
        $this->rootDir         = $rootDir;
        $this->varDir          = $varDir;
        $this->blocks          = new BlockRegistry();

        $directives = BuiltInDirectives::all($this);
        $typeMap    = $this->buildDirectiveMaps($directives);

        $this->tokenizer = new Tokenizer(
            fn(string $body): Token => $this->parseDirectiveBody($body)
        );
        $this->renderer = new Renderer($this, $typeMap);

        $this->runAnnotationScan($directives);
    }

    /**
     * The block-type schema registry. Owned by the engine, populated at
     * boot from `{@ block @}` annotations, supplied to directives
     * (BlocksDirective at render time) and to the PageLoader (for
     * content validation) by the application bootstrap.
     */
    public function blocks(): BlockRegistry
    {
        return $this->blocks;
    }

    /**
     * Project root directory, as supplied by the application bootstrap.
     * Empty string when the engine was constructed without host
     * context. Read by directives that need to resolve filesystem
     * paths (e.g. `ImageDirective`); other directives ignore it.
     */
    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * `paths.var` (runtime state directory), as supplied by the
     * application bootstrap. Empty string when the engine was
     * constructed without host context. Read by directives that
     * need to write into `<var>/cache/...` (e.g. `ImageDirective`).
     */
    public function varDir(): string
    {
        return $this->varDir;
    }

    /**
     * Render a template by name with a root context. Subsequent renders
     * of the same template reuse the compiled token list.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $name, array $context): string
    {
        return $this->renderer->renderTokens($this->compile($name), $context);
    }

    /**
     * Render a template in plain-text mode: variable output is NOT
     * HTML-escaped, raw output is NOT em-sanitised. Use for `text/plain`
     * mail bodies where HTML entities would be visible junk.
     *
     * Mode propagates via the context flag, so any {% include %} reached
     * from a text-mode render also runs in text mode (matches Twig's
     * autoescape inheritance).
     *
     * @param array<string, mixed> $context
     */
    public function renderText(string $name, array $context): string
    {
        $context[self::TEXT_MODE_FLAG] = true;
        return $this->renderer->renderTokens($this->compile($name), $context);
    }

    /**
     * Walk the directive list once, populating $this->keywordMap and the
     * returned token-type map. Conflicts in either map fail loud — adding
     * a directive that claims an already-owned keyword or token type is a
     * boot-time error, not a silent override.
     *
     * @param list<Directive> $directives
     * @return array<string, Directive>  token-type → directive
     */
    private function buildDirectiveMaps(array $directives): array
    {
        $typeMap = [];
        foreach ($directives as $directive) {
            foreach ($directive->keywords() as $keyword) {
                if (isset($this->keywordMap[$keyword])) {
                    throw new \RuntimeException("Directive keyword conflict: '{$keyword}'.");
                }
                $this->keywordMap[$keyword] = $directive;
            }
            foreach ($directive->handles() as $type) {
                if (isset($typeMap[$type])) {
                    throw new \RuntimeException("Directive token-type conflict: '{$type}'.");
                }
                $typeMap[$type] = $directive;
            }
        }
        return $typeMap;
    }

    /**
     * Parse the body of a `{% ... %}` directive into a Token. The
     * Tokenizer calls this for every directive occurrence; we extract
     * the leading keyword, look it up in the map, and let the directive
     * produce its own typed token.
     *
     * Two body shapes:
     *   - bare keyword            e.g. `endfor`, `blocks`           → args = []
     *   - keyword + args list     e.g. `if: cond`, `for: x, as: 'y'` → Expression::parseArgs
     *
     * The first key in the args list IS the keyword, by parser
     * construction — `Expression::parseArgs("if: cond")` returns
     * `['if' => 'cond']`. We pre-extract the keyword separately to keep
     * the bare form working without forcing a colon.
     */
    private function parseDirectiveBody(string $body): Token
    {
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('Empty directive body.');
        }
        if (preg_match('/^([a-zA-Z_][a-zA-Z_0-9]*)/', $body, $m) !== 1) {
            throw new \RuntimeException("Bad directive body: {$body}");
        }
        $keyword = $m[1];
        $rest    = ltrim(substr($body, strlen($keyword)));

        if ($rest === '') {
            $args = [];
        } elseif ($rest[0] === ':') {
            $args = Expression::parseArgs($body);
        } else {
            throw new \RuntimeException("Bad directive body: {$body}");
        }

        $directive = $this->keywordMap[$keyword] ?? null;
        if ($directive === null) {
            throw new \RuntimeException("Unknown directive: '{$keyword}'.");
        }
        return $directive->tokenize($keyword, $args);
    }

    /**
     * Boot-time annotation harvest.
     *
     *   1. Build the annotation-name → consumer map from any directive
     *      that implements AnnotationConsumer. Conflicts (two consumers
     *      claiming the same name) fail loud at boot.
     *   2. For every consumer, expand its eagerScanPaths() globs against
     *      the templates root, scan each file for `{@ name … @}` blocks
     *      via the Tokenizer, group by (template, name), merge each
     *      group's data, and call consumeAnnotation() once per
     *      (template, name) pair.
     *
     * Per-template merging rule: if the same annotation name appears
     * multiple times in one file (e.g. two `{@ block … @}` blocks in
     * the same partial), their key/value maps are merged. Duplicate
     * keys with conflicting values fail loud — silent overwrite would
     * mask authoring bugs.
     *
     * Path containment: every glob match is realpath-checked against
     * $templateRealDir before being read. Annotation harvesting runs
     * the same boundary as render-time template loading.
     *
     * @param list<Directive> $directives
     */
    private function runAnnotationScan(array $directives): void
    {
        /** @var array<string, AnnotationConsumer> $consumerMap */
        $consumerMap = [];
        foreach ($directives as $directive) {
            if (!$directive instanceof AnnotationConsumer) {
                continue;
            }
            foreach ($directive->annotationNames() as $name) {
                if (isset($consumerMap[$name])) {
                    throw new \RuntimeException("Annotation name conflict: '{$name}'.");
                }
                $consumerMap[$name] = $directive;
            }
        }
        if ($consumerMap === []) {
            return;
        }

        // Track which templates each consumer has seen, accumulate the
        // merged payload, then dispatch in one final pass per (consumer,
        // template) pair.
        /** @var array<string, array<string, array<string, string>>> $accum
         *       consumerName → templateName → mergedPayload
         */
        $accum = [];

        foreach ($directives as $directive) {
            if (!$directive instanceof AnnotationConsumer) {
                continue;
            }
            foreach ($directive->eagerScanPaths() as $pattern) {
                foreach ($this->expandScanGlob($pattern) as $templateName => $absPath) {
                    $src = @file_get_contents($absPath);
                    if ($src === false) {
                        throw new \RuntimeException("Annotation scan: cannot read {$absPath}");
                    }
                    foreach ($this->tokenizer->scanAnnotations($src) as $annotation) {
                        $consumer = $consumerMap[$annotation->name] ?? null;
                        if ($consumer === null) {
                            // An `{@ name @}` whose name nobody claims is
                            // ignored on the boot scan. This keeps adding
                            // a typo'd annotation a "your directive
                            // didn't see it" failure, not a hard boot
                            // crash that takes the whole site down.
                            continue;
                        }
                        $existing = $accum[$annotation->name][$templateName] ?? [];
                        foreach ($annotation->data as $k => $v) {
                            if (array_key_exists($k, $existing) && $existing[$k] !== $v) {
                                throw new \RuntimeException(
                                    "Conflicting annotation values for '{$annotation->name}.{$k}' in {$templateName}."
                                );
                            }
                            $existing[$k] = $v;
                        }
                        $accum[$annotation->name][$templateName] = $existing;
                    }
                }
            }
        }

        foreach ($accum as $name => $perTemplate) {
            $consumer = $consumerMap[$name];
            foreach ($perTemplate as $templateName => $payload) {
                $consumer->consumeAnnotation($templateName, $payload);
            }
        }
    }

    /**
     * Expand a glob pattern (relative to the templates root) into a list
     * of [templateName => absolutePath] entries. Each match is realpath-
     * contained against the templates root as defence-in-depth — the
     * glob root is already inside templateDir, but a symlink could in
     * principle point outside.
     *
     * Patterns are restricted to `[A-Za-z0-9/_*-]+\.html` so a directive
     * cannot accidentally request a path that escapes via "..".
     *
     * @return array<string, string>  templateName → absolute path
     */
    private function expandScanGlob(string $pattern): array
    {
        if ($pattern === '' || str_contains($pattern, '..') || str_contains($pattern, "\0")) {
            throw new \RuntimeException("Bad eager-scan pattern: '{$pattern}'.");
        }
        if (preg_match('#^[A-Za-z0-9/_*\-]+\.html$#', $pattern) !== 1) {
            throw new \RuntimeException("Bad eager-scan pattern: '{$pattern}'.");
        }
        $matches = glob($this->templateDir . '/' . $pattern, GLOB_NOSORT);
        if ($matches === false) {
            return [];
        }
        $out = [];
        $rootPrefix = $this->templateRealDir . DIRECTORY_SEPARATOR;
        foreach ($matches as $candidate) {
            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }
            if (!str_starts_with($real, $rootPrefix)) {
                throw new \RuntimeException("Eager-scan path escapes root: {$candidate}");
            }
            // Convert absolute path back into a template-name (root-relative,
            // forward slashes, no .html extension) — same form as
            // Engine::render() expects.
            $relative = substr($real, strlen($rootPrefix));
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $relative = preg_replace('/\.html$/', '', $relative) ?? $relative;
            $out[$relative] = $real;
        }
        return $out;
    }

    /**
     * @return list<Token>
     */
    private function compile(string $name): array
    {
        if (isset($this->compiled[$name])) {
            return $this->compiled[$name];
        }
        $path = $this->resolveTemplatePath($name);
        $src = @file_get_contents($path);
        if ($src === false) {
            throw new \RuntimeException("Template not readable: {$name}");
        }
        return $this->compiled[$name] = $this->tokenizer->tokenize($src);
    }

    private function resolveTemplatePath(string $name): string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '..')) {
            throw new \InvalidArgumentException("Bad template name: {$name}");
        }
        if (!preg_match('#^[A-Za-z0-9/_\-]+$#', $name)) {
            throw new \InvalidArgumentException("Bad template name: {$name}");
        }
        $candidate = $this->templateDir . '/' . $name . '.html';
        $real = realpath($candidate);
        if ($real === false) {
            throw new \RuntimeException("Template not found: {$name}");
        }
        // Defence-in-depth: the regex already excludes "..", but a symlink
        // or platform quirk could still land us outside the root.
        if (!str_starts_with($real, $this->templateRealDir . DIRECTORY_SEPARATOR)
            && $real !== $this->templateRealDir) {
            throw new \RuntimeException("Template path escapes root: {$name}");
        }
        return $real;
    }
}
