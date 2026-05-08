<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Assets;

/**
 * File-system browser for the WhimCMS core's `assets/` directory.
 *
 *   list(dir)               list immediate children, type-tagged
 *   mkdir(dir, name)        create subdirectory
 *   upload(dir, $_FILES)    accept one upload, mime-allowlisted
 *   rename(path, newName)   rename a file or directory
 *   recycle(path)           move file/dir to <assets>/.recycler/
 *   recyclerList()          listing of .recycler/
 *   recyclerPurge()         empty .recycler/ (manual operator action)
 *
 * Every public method validates path components against a tight regex
 * AND realpath-contains the resolved target under the asset root —
 * the same defence pattern as PageRepository / Recycler.
 *
 * Filename / dirname rules:
 *   - 1..128 bytes
 *   - regex: `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`
 *   - no leading `.` (avoids dotfiles + `..`)
 *   - file extension must be in EXTENSION_ALLOWLIST for upload; rename
 *     to a forbidden extension is rejected (defence against `.php` etc.)
 *
 * The `.recycler/` directory inside the asset root carries a deny-all
 * .htaccess on first creation — without it, recycled files would
 * remain web-accessible because `assets/` is a public Apache root.
 */
final class AssetBrowser
{
    private const RECYCLER_DIR = '.recycler';
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    /**
     * Extensions accepted for upload AND surfaced in the image-field
     * autocomplete / picker.
     *
     * SVG is deliberately ABSENT despite being a vector format: an
     * SVG can carry inline `<script>` blocks, and a direct browser
     * navigation to `/assets/<name>.svg` parses + renders the SVG
     * with same-origin script privileges (this is NOT mitigated by
     * `<img src=…>` rendering, which is safe — but we cannot
     * guarantee every consumer renders SVGs only via `<img>`). An
     * operator who needs SVG branding can SFTP the file in and
     * reference its path manually; the admin UI no longer accepts
     * SVG uploads or surfaces them as picker options.
     */
    private const EXTENSION_ALLOWLIST = [
        'png', 'jpg', 'jpeg', 'webp', 'gif',
        'woff2', 'ico',
    ];
    private const MAX_UPLOAD_BYTES = 10_485_760; // 10 MB

    private string $assetRealRoot;

    public function __construct(string $assetRoot)
    {
        $real = realpath($assetRoot);
        if ($real === false) {
            // Don't fail at construct — operator may not have an
            // assets/ folder yet. Create lazily on first write.
            if (!@mkdir($assetRoot, 0o755, true) && !is_dir($assetRoot)) {
                throw new \RuntimeException("Cannot create asset root: {$assetRoot}");
            }
            $real = realpath($assetRoot);
        }
        if ($real === false) {
            throw new \RuntimeException("Cannot resolve asset root: {$assetRoot}");
        }
        $this->assetRealRoot = $real;
    }

    public function root(): string
    {
        return $this->assetRealRoot;
    }

    /**
     * Recursively collect every image-extension file under the asset
     * root and return its base-relative path (`/assets/<rel>`). Used
     * by the page editor to feed a `<datalist>` autocomplete on
     * image fields. Hard-capped at $maxEntries to keep the rendered
     * datalist small enough to be responsive in the browser.
     *
     * Skips dotfiles / dot-dirs (so the recycler doesn't show up).
     *
     * @return list<string> sorted ascending
     */
    public function allImagePaths(int $maxEntries = 500): array
    {
        // SVG intentionally excluded — see EXTENSION_ALLOWLIST comment.
        $exts = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        $out  = [];
        $this->walkPaths($this->assetRealRoot, '', $exts, $out, $maxEntries, 0);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param list<string>  $exts  lowercase extensions to keep
     * @param list<string> &$out
     */
    private function walkPaths(string $absDir, string $relDir, array $exts, array &$out, int $max, int $depth): void
    {
        if ($depth > 8) return;     // hard recursion cap to defeat symlink loops
        if (count($out) >= $max) return;
        foreach ((array)@scandir($absDir) as $name) {
            if (!is_string($name) || $name === '' || $name[0] === '.') continue;
            // Same allowlist as upload — keeps filenames URL-safe and
            // skips anything an operator may have SFTP'd in with weird
            // bytes that would break the datalist option attribute.
            if (preg_match(self::NAME_PATTERN, $name) !== 1) continue;
            $full = $absDir . DIRECTORY_SEPARATOR . $name;
            // Defence: a symlink with an image extension pointing outside
            // the asset root would surface in the autocomplete; the
            // operator could pick it; the public site would then serve
            // the symlink TARGET (e.g. `/etc/passwd` masked as
            // `secret.jpg`). Skip any symlink whose realpath escapes
            // assetRealRoot. Intra-asset symlinks remain usable.
            if (is_link($full)) {
                $real = realpath($full);
                if ($real === false) continue;
                if (!str_starts_with($real, $this->assetRealRoot . DIRECTORY_SEPARATOR)) continue;
            }
            $rel  = $relDir === '' ? $name : $relDir . '/' . $name;
            if (is_dir($full)) {
                $this->walkPaths($full, $rel, $exts, $out, $max, $depth + 1);
                if (count($out) >= $max) return;
            } elseif (is_file($full)) {
                $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, $exts, true)) {
                    $out[] = '/assets/' . $rel;
                    if (count($out) >= $max) return;
                }
            }
        }
    }

    /**
     * @return array{dir:string, parent:?string, entries:list<array{name:string, type:string, size:int, mtime:int, path:string}>}
     */
    public function list(string $dir): array
    {
        $abs = $this->resolveDir($dir);
        $entries = [];
        foreach ((array)@scandir($abs) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') continue;
            // The recycler is browsable via a separate API.
            if ($name === self::RECYCLER_DIR && $dir === '') continue;
            $full = $abs . DIRECTORY_SEPARATOR . $name;
            $type = is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'other');
            $entries[] = [
                'name'  => $name,
                'type'  => $type,
                'size'  => $type === 'file' ? (int)@filesize($full) : 0,
                'mtime' => (int)(@filemtime($full) ?: 0),
                'path'  => trim($dir . '/' . $name, '/'),
            ];
        }
        usort($entries, static fn(array $a, array $b) =>
            ($a['type'] === 'dir' ? 0 : 1) <=> ($b['type'] === 'dir' ? 0 : 1)
            ?: strcmp($a['name'], $b['name'])
        );
        return [
            'dir'     => $dir,
            'parent'  => $dir === '' ? null : self::parentOf($dir),
            'entries' => $entries,
        ];
    }

    public function mkdir(string $parentDir, string $name): void
    {
        $this->assertName($name);
        $abs = $this->resolveDir($parentDir) . DIRECTORY_SEPARATOR . $name;
        if (file_exists($abs)) {
            throw new \RuntimeException("'{$name}' already exists.");
        }
        if (!@mkdir($abs, 0o755) && !is_dir($abs)) {
            throw new \RuntimeException("Cannot create directory: {$name}");
        }
        $this->assertContained(realpath($abs) ?: $abs);
    }

    /**
     * @param array{name:string, tmp_name:string, error:int, size:int} $file
     */
    public function upload(string $dir, array $file): string
    {
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . self::uploadErrorName((int)($file['error'] ?? -1)));
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new \RuntimeException('Not an uploaded file.');
        }
        if ((int)$file['size'] > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('File too large (max ' . (int)(self::MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB).');
        }
        $name = self::sanitizeUploadName((string)$file['name']);
        $this->assertName($name);
        $this->assertExtensionAllowed($name);

        // Defence-in-depth content sniffing for image extensions:
        // verify the bytes actually match an image of the claimed
        // type. An attacker can only write a file with an allowlisted
        // extension (above), but renaming `evil.php` to `evil.png`
        // would slip past extension-only validation. `getimagesize`
        // refuses to decode PHP, HTML, or arbitrary binaries, so a
        // mismatched-content upload fails here.
        //
        // `woff2` and `ico` aren't `getimagesize`-recognised, so they
        // skip this check (their parsing would need ext-specific
        // sniffers; the extension allowlist is the primary gate).
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $rasterImageExts = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        if (in_array($ext, $rasterImageExts, true)) {
            self::assertImageContentMatchesExt($file['tmp_name'] ?? '', $ext);
        }

        $abs = $this->resolveDir($dir);
        $target = $abs . DIRECTORY_SEPARATOR . $name;
        if (file_exists($target)) {
            // Append a counter to avoid collisions.
            $i = 1;
            $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $name) ?? $name;
            $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            do {
                $target = $abs . DIRECTORY_SEPARATOR . $base . '_' . $i . '.' . $ext;
                $i++;
            } while ($i < 1000 && file_exists($target));
            if ($i >= 1000) {
                throw new \RuntimeException('Too many filename collisions.');
            }
        }
        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('Cannot move uploaded file.');
        }
        @chmod($target, 0o644);
        $real = realpath($target) ?: $target;
        $this->assertContained($real);
        return basename($real);
    }

    public function rename(string $path, string $newName): void
    {
        $this->assertName($newName);
        $abs = $this->resolvePath($path);
        if (is_file($abs)) {
            $this->assertExtensionAllowed($newName);
        }
        $parent = dirname($abs);
        $target = $parent . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($target)) {
            throw new \RuntimeException("'{$newName}' already exists.");
        }
        if (!@rename($abs, $target)) {
            throw new \RuntimeException('Rename failed.');
        }
        $real = realpath($target) ?: $target;
        $this->assertContained($real);
    }

    /**
     * Move a file or directory into <assets>/.recycler/. The recycler
     * gets a deny-all .htaccess on first creation so its contents
     * are not web-accessible.
     */
    public function recycle(string $path): void
    {
        $abs = $this->resolvePath($path);
        $recDir = $this->ensureRecycler();
        // Encode the original path into the recycler filename so
        // restore (later phase) can reverse it.
        $orig = preg_replace('/[^A-Za-z0-9._-]/', '_', $path) ?? 'unknown';
        $base = $orig . '__' . gmdate('Y-m-d_His');
        $ext  = is_dir($abs) ? '' : ('.' . strtolower((string)pathinfo($abs, PATHINFO_EXTENSION)));
        $target = $recDir . DIRECTORY_SEPARATOR . $base . $ext;
        if (file_exists($target)) {
            $target = $recDir . DIRECTORY_SEPARATOR . $base . '_' . bin2hex(random_bytes(2)) . $ext;
        }
        if (!@rename($abs, $target)) {
            throw new \RuntimeException('Recycle move failed.');
        }
    }

    /**
     * @return list<array{name:string, type:string, size:int, mtime:int}>
     */
    public function recyclerList(): array
    {
        $recDir = $this->assetRealRoot . DIRECTORY_SEPARATOR . self::RECYCLER_DIR;
        if (!is_dir($recDir)) return [];
        $real = realpath($recDir);
        if ($real === false) return [];
        $this->assertContained($real);
        $out = [];
        foreach ((array)@scandir($real) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..' || $name === '.htaccess') continue;
            $full = $real . DIRECTORY_SEPARATOR . $name;
            $out[] = [
                'name'  => $name,
                'type'  => is_dir($full) ? 'dir' : 'file',
                'size'  => is_file($full) ? (int)@filesize($full) : 0,
                'mtime' => (int)(@filemtime($full) ?: 0),
            ];
        }
        usort($out, static fn(array $a, array $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /**
     * Drop recycler entries whose mtime is older than `$days` days.
     * Used by the backend-access sweeper. Returns count removed.
     *
     * Recycler files are originally `rename`d in (preserving the
     * source's mtime), so an item's mtime equals its original
     * last-modification — close enough to "deleted at" for the
     * purpose of an auto-sweep cutoff.
     */
    public function recyclerPurgeOlderThan(int $days): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('recyclerPurgeOlderThan: days must be >= 0.');
        }
        $recDir = $this->assetRealRoot . DIRECTORY_SEPARATOR . self::RECYCLER_DIR;
        if (!is_dir($recDir)) return 0;
        $real = realpath($recDir);
        if ($real === false) return 0;
        $this->assertContained($real);
        $cutoff = time() - ($days * 86400);
        $count = 0;
        foreach ((array)@scandir($real) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..' || $name === '.htaccess') continue;
            $path = $real . DIRECTORY_SEPARATOR . $name;
            $rp = realpath($path);
            if ($rp === false) continue;
            if (!str_starts_with($rp, $real . DIRECTORY_SEPARATOR)) continue;
            $mtime = @filemtime($rp);
            if ($mtime === false || $mtime > $cutoff) continue;
            if (is_dir($rp)) {
                if ($this->rmRecursive($rp)) $count++;
            } else {
                if (@unlink($rp)) $count++;
            }
        }
        return $count;
    }

    public function recyclerPurge(): int
    {
        $recDir = $this->assetRealRoot . DIRECTORY_SEPARATOR . self::RECYCLER_DIR;
        if (!is_dir($recDir)) return 0;
        $real = realpath($recDir);
        if ($real === false) return 0;
        $this->assertContained($real);
        $count = 0;
        foreach ((array)@scandir($real) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..' || $name === '.htaccess') continue;
            $path = $real . DIRECTORY_SEPARATOR . $name;
            $rp = realpath($path);
            if ($rp === false) continue;
            if (!str_starts_with($rp, $real . DIRECTORY_SEPARATOR)) continue;
            if (is_dir($rp)) {
                if ($this->rmRecursive($rp)) $count++;
            } else {
                if (@unlink($rp)) $count++;
            }
        }
        return $count;
    }

    // ----- internals -----

    private function ensureRecycler(): string
    {
        $rec = $this->assetRealRoot . DIRECTORY_SEPARATOR . self::RECYCLER_DIR;
        if (!is_dir($rec) && !@mkdir($rec, 0o755) && !is_dir($rec)) {
            throw new \RuntimeException('Cannot create asset recycler dir.');
        }
        $ht = $rec . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
            @chmod($ht, 0o644);
        }
        $real = realpath($rec) ?: $rec;
        $this->assertContained($real);
        return $real;
    }

    private function rmRecursive(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }
        if (is_dir($path)) {
            foreach ((array)@scandir($path) as $name) {
                if (!is_string($name) || $name === '.' || $name === '..') continue;
                $this->rmRecursive($path . DIRECTORY_SEPARATOR . $name);
            }
            return @rmdir($path);
        }
        return false;
    }

    private function resolveDir(string $relDir): string
    {
        $relDir = trim($relDir, '/');
        if ($relDir !== '' && !$this->isSafeRelPath($relDir)) {
            throw new \InvalidArgumentException('Bad directory path.');
        }
        $abs = $relDir === '' ? $this->assetRealRoot : $this->assetRealRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        $real = realpath($abs);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("Directory not found: {$relDir}");
        }
        $this->assertContained($real);
        return $real;
    }

    private function resolvePath(string $relPath): string
    {
        $relPath = trim($relPath, '/');
        if ($relPath === '' || !$this->isSafeRelPath($relPath)) {
            throw new \InvalidArgumentException('Bad path.');
        }
        $abs = $this->assetRealRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        $real = realpath($abs);
        if ($real === false || !file_exists($real)) {
            throw new \RuntimeException("Not found: {$relPath}");
        }
        $this->assertContained($real);
        return $real;
    }

    private function isSafeRelPath(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) return false;
        foreach (explode('/', $rel) as $part) {
            if (preg_match(self::NAME_PATTERN, $part) !== 1) return false;
        }
        return true;
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException("Invalid name: '{$name}'.");
        }
    }

    private function assertExtensionAllowed(string $filename): void
    {
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSION_ALLOWLIST, true)) {
            throw new \RuntimeException("Extension '.{$ext}' is not allowed.");
        }
    }

    private function assertContained(string $real): void
    {
        if ($real !== $this->assetRealRoot && !str_starts_with($real, $this->assetRealRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Path escapes asset root: {$real}");
        }
    }

    private static function parentOf(string $dir): string
    {
        $i = strrpos($dir, '/');
        return $i === false ? '' : substr($dir, 0, $i);
    }

    private static function sanitizeUploadName(string $original): string
    {
        // Browser-supplied filename: drop directory components and any
        // odd bytes; keep simple ASCII filename basis.
        $base = basename(str_replace(['\\', "\0"], ['/', ''], $original));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? 'upload';
        $base = ltrim($base, '.-');
        if ($base === '') $base = 'upload';
        return mb_substr($base, 0, 128, 'UTF-8');
    }

    /**
     * Use `getimagesize` to verify the upload's bytes encode the
     * raster format the extension claims. PHP's IMAGETYPE_* constants
     * map to MIME types; we accept the upload only when the detected
     * IMAGETYPE matches one of the type-codes legitimate for the
     * given extension.
     *
     * Throws on mismatch. Caller has already validated the extension
     * is in the raster allowlist.
     */
    private static function assertImageContentMatchesExt(string $tmpPath, string $ext): void
    {
        $info = @getimagesize($tmpPath);
        if (!is_array($info) || !isset($info[2]) || !is_int($info[2])) {
            throw new \RuntimeException("Upload bytes do not look like a valid image (extension was '.{$ext}').");
        }
        $detected = $info[2];
        $expected = match ($ext) {
            'png'         => [IMAGETYPE_PNG],
            'jpg', 'jpeg' => [IMAGETYPE_JPEG],
            'webp'        => [IMAGETYPE_WEBP],
            'gif'         => [IMAGETYPE_GIF],
            default       => [],
        };
        if (!in_array($detected, $expected, true)) {
            $detectedName = image_type_to_extension($detected, false) ?: 'unknown';
            throw new \RuntimeException(
                "Upload extension '.{$ext}' does not match detected content type '{$detectedName}'."
            );
        }
    }

    private static function uploadErrorName(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_OK         => 'ok',
            UPLOAD_ERR_INI_SIZE   => 'exceeds php.ini upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'exceeds form MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'partial upload',
            UPLOAD_ERR_NO_FILE    => 'no file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'no tmp dir',
            UPLOAD_ERR_CANT_WRITE => 'cannot write',
            UPLOAD_ERR_EXTENSION  => 'extension blocked',
            default               => 'unknown error ' . $code,
        };
    }
}
