<?php
declare(strict_types=1);

namespace H42\WhimCMS\Template\Directives;

use H42\WhimCMS\Config;
use H42\WhimCMS\Image\CroppedCache;
use H42\WhimCMS\Image\CroppedCacheSweeper;
use H42\WhimCMS\Image\CroppingProcessor;
use H42\WhimCMS\Image\Driver\GdDriver;
use H42\WhimCMS\Log;
use H42\WhimCMS\Path\AssetPathResolver;
use H42\WhimCMS\Template\Directive;
use H42\WhimCMS\Template\Engine;
use H42\WhimCMS\Template\Expression;
use H42\WhimCMS\Template\Renderer;
use H42\WhimCMS\Template\Sanitizer;
use H42\WhimCMS\Template\Token;

/**
 * `{% image: '<asset-path>', <params> %}` — server-side cropped /
 * resized image variant generator. Emits a URL string at the call site.
 *
 *
 * Two operating modes
 * -------------------
 *
 *   - Crop-to-fit  `width: N, height: N, focusX: F?, focusY: F?`
 *     Produces an exactly N×N image. The largest centred-on-focus
 *     rectangle of the requested aspect is taken from the source,
 *     then scaled to N×N. `focusX` / `focusY` are 0.0..1.0; default
 *     is 0.5 (centre). They only matter on the over-long axis of the
 *     source — a portrait source cropped square shifts vertically by
 *     focusY, a landscape source horizontally by focusX.
 *
 *   - Scale-only   `maxWidth: N?, maxHeight: N?`
 *     Aspect-preserving scale into a bounding box. Either bound can
 *     be omitted (unconstrained on that axis). No crop. Smart
 *     passthrough: when the source already fits AND no format change
 *     is requested, the source URL is emitted directly — no cache
 *     file, no PHP-served bytes for an unchanged image.
 *
 * Optional in both modes:
 *   - `format: jpg | png | webp | gif` — output format. Default
 *     mirrors the source. Forces a cache file even when the source
 *     would otherwise pass through.
 *
 *
 * Output
 * ------
 *
 * The directive emits a URL string into the surrounding template,
 * suitable for an `<img src="…">` attribute. Two URL shapes:
 *
 *   - `{{ BASE }}/img-c/<basename>-<hash>.<ext>` for processed images.
 *     The `<basename>` slice is purely cosmetic (helps when reading
 *     network-tab URLs); the `<hash>` is the routing-relevant part.
 *
 *   - `{{ BASE }}<asset-path>` for smart-passthrough cases. Apache
 *     serves the source file directly, no PHP hop.
 *
 *
 * Failure modes
 * -------------
 *
 *   - Asset path doesn't resolve / file missing / unsupported
 *     extension → empty string emitted, error logged. The HTML's
 *     `<img src="">` falls back to no image, which is the least
 *     surprising visible behaviour. (We do NOT emit a 1px transparent
 *     gif placeholder — that would mask a deploy bug.)
 *
 *   - GD missing → behaviour driven by `images.fallback_when_no_gd`:
 *       'serve_original' → emit the source URL (passthrough), warn
 *                          (full-size payloads, but site keeps working)
 *       'serve_fail'     → emit empty string, error logged. Default.
 *
 *   - Encode failure (rare; corrupt source, etc.) → empty string
 *     emitted, error logged. Page still renders.
 *
 *
 * Security
 * --------
 *
 *   - Source path goes through `Path\AssetPathResolver` with the
 *     image extension whitelist — no path traversal, no escape
 *     from the configured asset roots.
 *
 *   - Cache filename hash is derived from `(real-path, mtime, params)`
 *     so authors cannot collide cache files across sources, and a
 *     source change automatically produces a new filename.
 *
 *   - The cropped cache is **write-only from the directive**. The
 *     paired `CroppedServer` endpoint is read-only, so URL probing
 *     cannot trigger cache writes. The cache surface is therefore
 *     bounded by what real templates ask for at render time.
 *
 *   - Decompression-bomb guards (max source bytes, max source pixels)
 *     run before GD touches the source.
 */
final class ImageDirective implements Directive
{
    /** Image-file extensions the directive accepts as source. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Decompression-bomb guard defaults. Override per deployment via
     * `config/images.php → max_source_bytes` / `max_source_pixels`.
     * Both values are picked to fit any realistic photo source while
     * bounding GD's memory allocation per worker.
     */
    private const DEFAULT_MAX_SOURCE_BYTES  = 25 * 1024 * 1024;
    private const DEFAULT_MAX_SOURCE_PIXELS = 50_000_000;

    /** Lazy services. Built on first render, then cached for the request. */
    private ?AssetPathResolver $assetPaths = null;
    private ?CroppedCache $cache = null;
    private ?CroppingProcessor $processor = null;
    private ?CroppedCacheSweeper $sweeper = null;
    private ?string $basePath = null;
    private string $fallbackWhenNoGd = 'serve_fail';
    private int $maxSourceBytes = self::DEFAULT_MAX_SOURCE_BYTES;
    private int $maxSourcePixels = self::DEFAULT_MAX_SOURCE_PIXELS;

    public function __construct(private Engine $engine)
    {
    }

    public function keywords(): array
    {
        return ['image'];
    }

    /**
     * Validate the parameter combination at compile time so a typo'd
     * directive fails loud at boot/first-compile, not silently at
     * render time. Stores the normalised parameters on the token so
     * render() can dispatch without re-parsing.
     */
    public function tokenize(string $keyword, array $args): Token
    {
        if (!isset($args['image'])) {
            throw new \RuntimeException("Directive 'image' missing source path argument.");
        }
        $hasWidth   = isset($args['width']);
        $hasHeight  = isset($args['height']);
        $hasMaxW    = isset($args['maxWidth']);
        $hasMaxH    = isset($args['maxHeight']);

        if (($hasWidth || $hasHeight) && ($hasMaxW || $hasMaxH)) {
            throw new \RuntimeException(
                "Directive 'image': cannot mix 'width'/'height' with 'maxWidth'/'maxHeight'."
            );
        }
        if ($hasWidth !== $hasHeight) {
            throw new \RuntimeException(
                "Directive 'image': 'width' and 'height' must be set together (crop-to-fit needs both)."
            );
        }
        if (!$hasWidth && !$hasMaxW && !$hasMaxH) {
            throw new \RuntimeException(
                "Directive 'image': need at least one of 'width'+'height' or 'maxWidth'/'maxHeight'."
            );
        }
        if (isset($args['format'])) {
            $fmt = strtolower(Expression::stripQuotes($args['format']));
            if (GdDriver::typeForExt($fmt) === null) {
                throw new \RuntimeException(
                    "Directive 'image': invalid format '{$fmt}' (allowed: jpg, png, webp, gif)."
                );
            }
        }

        return new Token('image', [
            'image'     => $args['image'],
            'width'     => $args['width']     ?? null,
            'height'    => $args['height']    ?? null,
            'maxWidth'  => $args['maxWidth']  ?? null,
            'maxHeight' => $args['maxHeight'] ?? null,
            'focusX'    => $args['focusX']    ?? null,
            'focusY'    => $args['focusY']    ?? null,
            'format'    => $args['format']    ?? null,
        ]);
    }

    public function handles(): array
    {
        return ['image'];
    }

    public function render(Token $token, array $ctx, Renderer $renderer): string
    {
        $payload = $token->payload;
        $assetPath = Sanitizer::stringify(Expression::evaluate((string)$payload['image'], $ctx));
        if ($assetPath === '' || $assetPath[0] !== '/') {
            Log::warn('ImageDirective: empty or non-rooted asset path', ['path' => $assetPath]);
            return '';
        }

        $params = $this->resolveParams($payload, $ctx);
        if ($params === null) {
            return '';
        }
        $basePath = $this->basePath($ctx);

        $services = $this->ensureServices();
        if ($services === null) {
            // Engine wasn't constructed with host context (rootDir/varDir).
            // Refuse silently — render with empty src so the page still
            // loads. Caller-side log already happened in ensureServices().
            return '';
        }

        $sourcePath = $services['assetPaths']->resolve($assetPath, self::ALLOWED_EXTENSIONS);
        if ($sourcePath === null) {
            Log::warn('ImageDirective: asset not resolvable', ['path' => $assetPath]);
            return '';
        }

        // Decompression-bomb guards — before we ask GD to do anything.
        $bytes = @filesize($sourcePath);
        if ($bytes === false || $bytes > $this->maxSourceBytes) {
            Log::warn('ImageDirective: source too large', ['path' => $assetPath, 'bytes' => $bytes]);
            return '';
        }
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            Log::warn('ImageDirective: source not a recognisable image', ['path' => $assetPath]);
            return '';
        }
        if (((int)$info[0] * (int)$info[1]) > $this->maxSourcePixels) {
            Log::warn('ImageDirective: source pixel-count exceeds cap', ['path' => $assetPath]);
            return '';
        }

        $sourceMtime = @filemtime($sourcePath);
        if ($sourceMtime === false) {
            return '';
        }
        $sourceType = (int)$info[2];

        // Determine the output extension up front so we can build the
        // cache filename. Format param wins; otherwise mirror source.
        $outputType = $params['format'] !== null
            ? (GdDriver::typeForExt($params['format']) ?? $sourceType)
            : $sourceType;
        $outputExt = GdDriver::extForType($outputType);

        // Hash inputs: real source path + mtime + canonicalised params.
        $hashParams = $this->hashParams($params);
        $filename   = $services['cache']->filenameFor($sourcePath, $sourceMtime, $hashParams, $outputExt);

        // Cache hit — emit URL without doing any work.
        if ($services['cache']->exists($filename)) {
            return $basePath . '/img-c/' . $filename;
        }

        // No cache file — generate.
        if (!GdDriver::isAvailable()) {
            if ($this->fallbackWhenNoGd === 'serve_original') {
                Log::warn('ImageDirective: GD unavailable, serving original (full size)', [
                    'path' => $assetPath,
                ]);
                return $basePath . $assetPath;
            }
            Log::error('ImageDirective: GD unavailable, refusing to render', [
                'path' => $assetPath,
            ]);
            return '';
        }

        $result = $params['mode'] === 'crop'
            ? $services['processor']->cropToFit($sourcePath, $params['cropArgs'])
            : $services['processor']->scaleWithin($sourcePath, $params['scaleArgs']);

        if ($result === null) {
            Log::error('ImageDirective: render failed', ['path' => $assetPath]);
            return '';
        }
        if ($result['type'] === 'passthrough') {
            // Source already fits — emit source URL, no cache file.
            return $basePath . $assetPath;
        }

        // Rendered. Store in cache, trigger sweep on a write, return URL.
        $stored = $services['cache']->store($filename, $result['bytes']);
        if (!$stored) {
            Log::error('ImageDirective: cache write failed', ['path' => $assetPath, 'filename' => $filename]);
            return '';
        }
        $services['sweeper']?->sweepIfDue();
        return $basePath . '/img-c/' . $filename;
    }

    public function renderBlock(Token $open, array $body, array $ctx, Renderer $renderer): string
    {
        throw new \LogicException('ImageDirective is not a block directive.');
    }

    // ---- internals ------------------------------------------------------

    /**
     * Evaluate the directive's typed parameters against the render
     * context, producing a normalised structure ready for the
     * processor. Returns null if a numeric parameter doesn't evaluate
     * cleanly (rare; usually means the author wrote `width: foo` where
     * `foo` resolves to a string).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $ctx
     * @return array{mode:string, format:?string, cropArgs?:array<string,mixed>, scaleArgs?:array<string,mixed>}|null
     */
    private function resolveParams(array $payload, array $ctx): ?array
    {
        $format = null;
        if ($payload['format'] !== null) {
            $format = strtolower((string)Expression::stripQuotes((string)$payload['format']));
        }

        if ($payload['width'] !== null) {
            $w = $this->intParam($payload['width'], $ctx, 'width');
            $h = $this->intParam($payload['height'], $ctx, 'height');
            if ($w === null || $h === null || $w < 1 || $h < 1) {
                return null;
            }
            // Focus is tolerant: null/missing/non-numeric → 0.5 (centre).
            // Authors often write `focusX: attrs.focusX` knowing the
            // attribute is optional in the .md — letting that evaluate
            // to "centre" is the friendly default.
            $fx = $this->optionalFloat($payload['focusX'], $ctx, 'focusX', 0.5);
            $fy = $this->optionalFloat($payload['focusY'], $ctx, 'focusY', 0.5);
            return [
                'mode'   => 'crop',
                'format' => $format,
                'cropArgs' => [
                    'width'  => $w,
                    'height' => $h,
                    'focusX' => max(0.0, min(1.0, $fx)),
                    'focusY' => max(0.0, min(1.0, $fy)),
                    'format' => $format,
                ],
            ];
        }

        // Scale-only.
        $maxW = $payload['maxWidth']  !== null ? $this->intParam($payload['maxWidth'], $ctx, 'maxWidth') : null;
        $maxH = $payload['maxHeight'] !== null ? $this->intParam($payload['maxHeight'], $ctx, 'maxHeight') : null;
        if (($payload['maxWidth'] !== null && $maxW === null) || ($payload['maxHeight'] !== null && $maxH === null)) {
            return null;
        }
        return [
            'mode'   => 'scale',
            'format' => $format,
            'scaleArgs' => [
                'maxWidth'  => $maxW,
                'maxHeight' => $maxH,
                'format'    => $format,
            ],
        ];
    }

    /**
     * Build the params subset that goes into the cache hash. Kept
     * separate from `resolveParams` because the hash needs the same
     * keys whether the directive is in crop or scale mode (so a
     * params re-shape doesn't accidentally invalidate working caches
     * on a future refactor).
     *
     * @param array{mode:string, format:?string, cropArgs?:array<string,mixed>, scaleArgs?:array<string,mixed>} $params
     * @return array<string, scalar|null>
     */
    private function hashParams(array $params): array
    {
        $h = ['mode' => $params['mode'], 'format' => $params['format']];
        if ($params['mode'] === 'crop') {
            $h += $params['cropArgs'];
        } else {
            $h += $params['scaleArgs'];
        }
        // Drop nulls — they're equivalent to "absent" and we want the
        // canonical form to match across crop/scale shapes.
        return array_filter($h, static fn($v): bool => $v !== null);
    }

    private function intParam(mixed $rawExpr, array $ctx, string $name): ?int
    {
        $value = Expression::evaluate((string)$rawExpr, $ctx);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }
        if (is_float($value)) {
            return (int)round($value);
        }
        Log::warn("ImageDirective: '{$name}' is not a positive integer", ['value' => $value]);
        return null;
    }

    /**
     * Float param with a tolerant default. Used for `focusX` / `focusY`
     * because authors typically write `focusX: attrs.focusX` knowing
     * the attribute is optional — null evaluation should fall through
     * to the default (centre), not abort the whole directive.
     */
    private function optionalFloat(mixed $rawExpr, array $ctx, string $name, float $default): float
    {
        if ($rawExpr === null) {
            return $default;
        }
        $value = Expression::evaluate((string)$rawExpr, $ctx);
        if ($value === null) {
            return $default;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }
        Log::warn("ImageDirective: '{$name}' is not numeric", ['value' => $value]);
        return $default;
    }

    /**
     * Pull `BASE` from the render context. Already validated by the
     * dispatcher; we just need to prefix it onto the emitted URL so
     * the result works under any deployment base path.
     *
     * @param array<string, mixed> $ctx
     */
    private function basePath(array $ctx): string
    {
        return is_string($ctx['BASE'] ?? null) ? $ctx['BASE'] : '';
    }

    /**
     * Lazy-build the directive's services on first render. Returns
     * null if the engine wasn't constructed with a `rootDir`/`varDir`
     * — in that case the directive cannot operate, but we don't want
     * to crash the engine at boot just because a host happens to use
     * the engine without image support.
     *
     * @return array{assetPaths:AssetPathResolver, cache:CroppedCache, processor:CroppingProcessor, sweeper:?CroppedCacheSweeper}|null
     */
    private function ensureServices(): ?array
    {
        if ($this->cache !== null && $this->assetPaths !== null && $this->processor !== null) {
            return [
                'assetPaths' => $this->assetPaths,
                'cache'      => $this->cache,
                'processor'  => $this->processor,
                'sweeper'    => $this->sweeper,
            ];
        }

        $rootDir = $this->engine->rootDir();
        $varDir  = $this->engine->varDir();
        if ($rootDir === '' || $varDir === '') {
            Log::error('ImageDirective: engine constructed without rootDir/varDir; image directive disabled');
            return null;
        }

        $images = (array)Config::get('images', []);
        // Resolve the asset-roots list. Two input shapes accepted:
        //   - allowed_roots: ['assets', 'theme/assets']  (preferred, list)
        //   - allowed_root:  'assets'                    (BC, single string)
        $assetRoots = [];
        if (isset($images['allowed_roots']) && is_array($images['allowed_roots'])) {
            foreach ($images['allowed_roots'] as $r) {
                if (is_string($r) && $r !== '') {
                    $assetRoots[] = trim($r, '/');
                }
            }
        }
        if ($assetRoots === [] && isset($images['allowed_root']) && is_string($images['allowed_root'])) {
            $assetRoots = [trim($images['allowed_root'], '/')];
        }
        if ($assetRoots === []) {
            $assetRoots = ['assets'];
        }

        $jpegQuality = (int)($images['jpg_quality'] ?? 85);
        $maxBytes    = isset($images['max_source_bytes'])  ? (int)$images['max_source_bytes']  : self::DEFAULT_MAX_SOURCE_BYTES;
        $maxPixels   = isset($images['max_source_pixels']) ? (int)$images['max_source_pixels'] : self::DEFAULT_MAX_SOURCE_PIXELS;
        $this->maxSourceBytes  = max(64 * 1024, $maxBytes);
        $this->maxSourcePixels = max(100_000,   $maxPixels);

        // GD-missing fallback. Default `serve_fail` (refuse) because
        // an empty <img src=> is glaringly broken in dev tools — fast
        // feedback for the operator to install GD. Operator can opt
        // into `serve_original` (passthrough at full resolution) when
        // GD genuinely cannot be installed.
        $fallback = (string)($images['fallback_when_no_gd'] ?? 'serve_fail');
        $this->fallbackWhenNoGd = $fallback === 'serve_original' ? 'serve_original' : 'serve_fail';

        $cacheDir = $varDir . '/cache/img-cropped';
        $stateDir = $varDir . '/state';

        $this->assetPaths = new AssetPathResolver($rootDir, $assetRoots);
        $this->cache      = new CroppedCache($cacheDir);
        $this->processor  = new CroppingProcessor(new GdDriver($jpegQuality));

        $sweepInterval = (int)($images['cropped_cache_sweep_interval'] ?? 86400);
        $maxAge        = (int)($images['cropped_cache_max_age']        ?? 30 * 86400);
        $this->sweeper = new CroppedCacheSweeper(
            $cacheDir,
            $stateDir . '/.cache-sweep-img-cropped',
            $sweepInterval,
            $rootDir,
            $maxAge,
        );

        return [
            'assetPaths' => $this->assetPaths,
            'cache'      => $this->cache,
            'processor'  => $this->processor,
            'sweeper'    => $this->sweeper,
        ];
    }
}
