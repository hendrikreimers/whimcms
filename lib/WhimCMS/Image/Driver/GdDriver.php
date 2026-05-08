<?php
declare(strict_types=1);

namespace H42\WhimCMS\Image\Driver;

/**
 * Thin wrapper around the PHP `gd` extension.
 *
 * Every GD call in the codebase goes through this class — `Resizer`,
 * `CroppingProcessor`, anything future. Two reasons:
 *
 *   1. **Single point of replacement.** If we ever swap GD for ext-gd-
 *      successor, ImageMagick, libvips, or a remote service, only this
 *      file changes. Callers don't need to know which backend is in
 *      play; they just hand in source bytes + an op + ask for output
 *      bytes.
 *
 *   2. **Resource discipline.** GD images are PHP-managed but each
 *      `imagecreate*` call allocates a chunk of memory the size of
 *      width × height × 4 bytes. Wrapping every load/save in this
 *      class makes it obvious where to add `imagedestroy()` calls
 *      and where the alpha-channel preservation logic lives.
 *
 * The driver itself does no caching, no IO outside loading source
 * files, no logging. It encodes one operation: take a source path,
 * apply a transform, return bytes + mime. Anything else is the
 * caller's job.
 *
 * Failure mode is uniform: every method that can fail returns null
 * on failure (no exceptions, no warnings escape). The caller decides
 * how to react.
 */
final class GdDriver
{
    public function __construct(private int $jpegQuality = 85)
    {
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Probe a source file: returns dimensions and the IMAGETYPE_* code,
     * or null if the file is unreadable or not an image GD recognises.
     *
     * @return array{width:int, height:int, type:int, mime:string}|null
     */
    public function probe(string $sourcePath): ?array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }
        [$w, $h] = $info;
        if ((int)$w <= 0 || (int)$h <= 0) {
            return null;
        }
        return [
            'width'  => (int)$w,
            'height' => (int)$h,
            'type'   => (int)$info[2],
            'mime'   => image_type_to_mime_type((int)$info[2]),
        ];
    }

    /**
     * Resize the source to a new width, preserving aspect ratio. Never
     * upsamples — a `$targetWidth >= sourceWidth` returns the source
     * bytes verbatim so the cache doesn't churn on bigger-than-source
     * widths.
     *
     * Output format mirrors source (JPEG → JPEG, etc.). For format
     * conversion use encode() directly.
     *
     * Returns [bytes, mime] or null on any failure.
     *
     * @return array{0:string, 1:string}|null
     */
    public function resizeToWidth(string $sourcePath, int $targetWidth): ?array
    {
        $probe = $this->probe($sourcePath);
        if ($probe === null) {
            return null;
        }
        if ($targetWidth >= $probe['width']) {
            $bytes = @file_get_contents($sourcePath);
            return $bytes === false ? null : [$bytes, $probe['mime']];
        }
        $newH = (int) round($probe['height'] * ($targetWidth / $probe['width']));
        return $this->transform($sourcePath, $probe, $targetWidth, $newH, /*srcX*/0, /*srcY*/0, $probe['width'], $probe['height']);
    }

    /**
     * Crop a region of the source then scale it to the target box.
     *
     * `$srcX/Y/W/H` defines the rectangle of the source to take; the
     * result is rendered at exactly `$targetW × $targetH`. The caller
     * (CroppingProcessor) computes the rectangle from focus + target
     * dimensions; this method does no math, it only executes.
     *
     * Output format mirrors source unless `$outputType` is explicitly
     * set — used for format conversion (e.g. JPEG source → WebP cache).
     *
     * Returns [bytes, mime] or null on any failure.
     *
     * @param array{width:int, height:int, type:int, mime:string} $probe
     * @return array{0:string, 1:string}|null
     */
    public function cropAndResize(
        string $sourcePath,
        array $probe,
        int $srcX,
        int $srcY,
        int $srcW,
        int $srcH,
        int $targetW,
        int $targetH,
        ?int $outputType = null,
    ): ?array {
        return $this->transform($sourcePath, $probe, $targetW, $targetH, $srcX, $srcY, $srcW, $srcH, $outputType);
    }

    /**
     * Map an IMAGETYPE_* code to the file extension we'd write.
     */
    public static function extForType(int $type): string
    {
        return match ($type) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
            default        => 'bin',
        };
    }

    /**
     * Map a caller-supplied format string ('jpg', 'png', 'webp', 'gif')
     * to the IMAGETYPE_* code, or null for an unsupported value.
     * Used by callers that take a `format:` parameter.
     */
    public static function typeForExt(string $ext): ?int
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => IMAGETYPE_JPEG,
            'png'         => IMAGETYPE_PNG,
            'webp'        => IMAGETYPE_WEBP,
            'gif'         => IMAGETYPE_GIF,
            default       => null,
        };
    }

    // ---- internals ------------------------------------------------------

    /**
     * Single internal transform: load → allocate dest → copyresampled
     * → encode → free. Both `resizeToWidth` and `cropAndResize` route
     * through here so the alpha-handling and resource-cleanup paths
     * exist in exactly one place.
     *
     * @param array{width:int, height:int, type:int, mime:string} $probe
     * @return array{0:string, 1:string}|null
     */
    private function transform(
        string $sourcePath,
        array $probe,
        int $targetW,
        int $targetH,
        int $srcX,
        int $srcY,
        int $srcW,
        int $srcH,
        ?int $outputType = null,
    ): ?array {
        if ($targetW <= 0 || $targetH <= 0 || $srcW <= 0 || $srcH <= 0) {
            return null;
        }
        $src = $this->loadImage($sourcePath, $probe['type']);
        if ($src === null) {
            return null;
        }
        $dst = imagecreatetruecolor($targetW, $targetH);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }

        $writeType = $outputType ?? $probe['type'];

        // Preserve transparency for PNG/GIF/WebP destinations. JPEG
        // can't carry alpha so a JPEG destination flattens against
        // the GD default (black). Callers wanting a white background
        // for transparent → JPEG conversions must add that themselves.
        if ($writeType === IMAGETYPE_PNG || $writeType === IMAGETYPE_GIF || $writeType === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
            }
        }

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetW, $targetH, $srcW, $srcH);

        ob_start();
        $ok = match ($writeType) {
            IMAGETYPE_JPEG => imagejpeg($dst, null, $this->jpegQuality),
            IMAGETYPE_PNG  => imagepng($dst, null, 6),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, null, $this->jpegQuality) : false,
            IMAGETYPE_GIF  => imagegif($dst),
            default        => false,
        };
        $bytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok || !is_string($bytes) || $bytes === '') {
            return null;
        }
        return [$bytes, image_type_to_mime_type($writeType)];
    }

    /**
     * Load a source file as a GdImage. Returns null on any failure
     * (unreadable file, unsupported type, GD error). Errors are
     * suppressed — caller checks the return value.
     */
    private function loadImage(string $path, int $type): ?\GdImage
    {
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            default        => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }
}
