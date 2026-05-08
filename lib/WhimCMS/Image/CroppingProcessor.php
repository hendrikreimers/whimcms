<?php
declare(strict_types=1);

namespace H42\WhimCMS\Image;

use H42\WhimCMS\Image\Driver\GdDriver;

/**
 * Crop + resize logic for the `{% image %}` template directive.
 *
 * Two operating modes, one method each:
 *
 *   - `cropToFit($src, ['width' => N, 'height' => N, ...])` —
 *     produces an exactly N×N image. The source's biggest centred-on-
 *     focus rectangle of the right aspect ratio is taken, then scaled
 *     to N×N. `focusX`/`focusY` (0.0..1.0) shift the crop window
 *     along the source's longer axis.
 *
 *   - `scaleWithin($src, ['maxWidth' => N, 'maxHeight' => N, ...])` —
 *     scales the source down to fit inside a bounding box, preserving
 *     aspect ratio. No crop. Either bound can be omitted (meaning
 *     "unconstrained on this axis"). Smart passthrough: if the source
 *     already fits, no work is done.
 *
 * Both modes return one of three shapes:
 *
 *   null                                     — failure (load/encode/probe)
 *   ['type' => 'passthrough']                — source bytes can be served
 *                                              as-is; caller should emit
 *                                              the source URL, not a
 *                                              cropped-cache URL
 *   ['type' => 'rendered',
 *    'bytes' => string,
 *    'mime'  => string,
 *    'ext'   => string]                      — processed bytes ready to
 *                                              write into the cache
 *
 * The processor doesn't touch the cache itself. It also doesn't
 * resolve `$sourcePath` against any allowlist — the caller (the
 * `ImageDirective`) does that via `Path\AssetPathResolver` before
 * handing the resolved real path here.
 *
 * Format conversion: an optional `format` key in either params array
 * forces the output format (`jpg`, `png`, `webp`, `gif`). Without
 * it, the output mirrors the source format. Format conversion alone
 * (no resize, no crop) still produces a rendered file — the bytes
 * differ from the source so passthrough wouldn't be safe.
 */
final class CroppingProcessor
{
    public function __construct(private GdDriver $driver)
    {
    }

    /**
     * Crop-to-fit a source image to exactly `$params['width'] × $params['height']`.
     *
     * Algorithm:
     *   1. Compute the source aspect (W/H) and target aspect (w/h).
     *   2. If they match, no crop — full source is scaled to the target.
     *   3. Otherwise, take the largest rectangle of the target's aspect
     *      from inside the source, positioned by focusX/focusY:
     *        - target wider than source aspect → crop top/bottom
     *        - target taller than source aspect → crop left/right
     *      Focus is the relative position of the crop window's centre
     *      along the cropped axis (0.0 = top/left, 1.0 = bottom/right).
     *   4. Scale the cropped rectangle to exactly target dims.
     *
     * No-upscale guard: if the source is smaller than the target box
     * in BOTH dimensions, the result is rendered at source-cropped
     * dimensions instead (smaller than requested but at least not
     * blurry). Passthrough is not used here because the caller has
     * specifically asked for crop-to-fit semantics.
     *
     * @param array{width:int, height:int, focusX:float, focusY:float, format:?string} $params
     * @return array{type:string, bytes?:string, mime?:string, ext?:string}|null
     */
    public function cropToFit(string $sourcePath, array $params): ?array
    {
        $probe = $this->driver->probe($sourcePath);
        if ($probe === null) {
            return null;
        }
        $srcW = $probe['width'];
        $srcH = $probe['height'];
        $tgtW = $params['width'];
        $tgtH = $params['height'];
        $focusX = $params['focusX'];
        $focusY = $params['focusY'];

        // Determine the crop rectangle (in source coords) that has the
        // target aspect, sized to fit inside the source, positioned
        // along the over-long axis according to the focus point.
        $srcAspect = $srcW / $srcH;
        $tgtAspect = $tgtW / $tgtH;

        if (abs($srcAspect - $tgtAspect) < 1e-6) {
            // Same aspect — no crop. Full source goes into the target.
            $cropX = 0;
            $cropY = 0;
            $cropW = $srcW;
            $cropH = $srcH;
        } elseif ($srcAspect > $tgtAspect) {
            // Source is relatively wider → crop horizontally.
            $cropH = $srcH;
            $cropW = (int) round($srcH * $tgtAspect);
            $cropY = 0;
            $cropX = (int) round(($srcW - $cropW) * max(0.0, min(1.0, $focusX)));
        } else {
            // Source is relatively taller → crop vertically.
            $cropW = $srcW;
            $cropH = (int) round($srcW / $tgtAspect);
            $cropX = 0;
            $cropY = (int) round(($srcH - $cropH) * max(0.0, min(1.0, $focusY)));
        }

        // No-upscale guard: if both target dims exceed the cropped
        // source dims, render at the cropped source size instead.
        // Better blurry-but-correct than blurry-and-misleading.
        $finalW = min($tgtW, $cropW);
        $finalH = min($tgtH, $cropH);

        $writeType = $this->resolveWriteType($params['format'] ?? null, $probe['type']);

        $result = $this->driver->cropAndResize(
            $sourcePath,
            $probe,
            $cropX, $cropY, $cropW, $cropH,
            $finalW, $finalH,
            $writeType,
        );
        if ($result === null) {
            return null;
        }
        [$bytes, $mime] = $result;
        return [
            'type'  => 'rendered',
            'bytes' => $bytes,
            'mime'  => $mime,
            'ext'   => GdDriver::extForType($writeType),
        ];
    }

    /**
     * Scale the source down to fit inside `$params['maxWidth'] ×
     * $params['maxHeight']`, preserving aspect ratio. Either bound
     * can be null meaning "no constraint on this axis".
     *
     * Smart passthrough: when the source already fits in the bounding
     * box AND no format conversion is requested, returns
     * `['type' => 'passthrough']` so the caller can emit the source
     * URL directly — no cache file written, no PHP-served byte stream
     * for an unchanged image.
     *
     * @param array{maxWidth:?int, maxHeight:?int, format:?string} $params
     * @return array{type:string, bytes?:string, mime?:string, ext?:string}|null
     */
    public function scaleWithin(string $sourcePath, array $params): ?array
    {
        $probe = $this->driver->probe($sourcePath);
        if ($probe === null) {
            return null;
        }
        $srcW = $probe['width'];
        $srcH = $probe['height'];
        $maxW = $params['maxWidth']  ?? PHP_INT_MAX;
        $maxH = $params['maxHeight'] ?? PHP_INT_MAX;
        $writeType = $this->resolveWriteType($params['format'] ?? null, $probe['type']);

        // Smart passthrough — only when no resize AND no format change.
        $sourceFitsBox = $srcW <= $maxW && $srcH <= $maxH;
        $formatUnchanged = $writeType === $probe['type'];
        if ($sourceFitsBox && $formatUnchanged) {
            return ['type' => 'passthrough'];
        }

        // Compute the scale factor: the smaller of the two ratios so
        // the result fits in the box on both axes. Floor at 1.0 so
        // we never upscale past source — upscaling produces blurry
        // output and wastes bytes for no benefit.
        $scale = min(1.0, $maxW / $srcW, $maxH / $srcH);
        $finalW = max(1, (int) round($srcW * $scale));
        $finalH = max(1, (int) round($srcH * $scale));

        $result = $this->driver->cropAndResize(
            $sourcePath,
            $probe,
            0, 0, $srcW, $srcH,
            $finalW, $finalH,
            $writeType,
        );
        if ($result === null) {
            return null;
        }
        [$bytes, $mime] = $result;
        return [
            'type'  => 'rendered',
            'bytes' => $bytes,
            'mime'  => $mime,
            'ext'   => GdDriver::extForType($writeType),
        ];
    }

    /**
     * Decide the IMAGETYPE_* code to write. Uses the explicit `format`
     * parameter when provided (already validated by the caller as one
     * of the supported short names); otherwise mirrors the source.
     */
    private function resolveWriteType(?string $format, int $sourceType): int
    {
        if ($format === null) {
            return $sourceType;
        }
        $type = GdDriver::typeForExt($format);
        return $type ?? $sourceType;
    }
}
