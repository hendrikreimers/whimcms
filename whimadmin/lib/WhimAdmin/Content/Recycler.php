<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimCMS\Content\Identifiers;

/**
 * Soft-delete store for content files.
 *
 *   Path:    <contentDir>/.recycler/<lang>__<slug>__<utc-ts>.md
 *
 * Page deletion (Phase 4 controller) calls `recycle($lang, $slug)`
 * to move the live file out of `<contentDir>/<lang>/` and into the
 * recycler tree. The recycler is a flat directory (no per-language
 * sub-folders) — listing it gives an overview of every soft-deleted
 * page across languages, which is what the "purge recycler" UI
 * (Phase 4) wants.
 *
 * The recycler is invisible to the public site:
 *   - The first segment `.recycler` doesn't match any lang regex
 *     so PageLoader can't reach it.
 *   - The bundled `content/.htaccess` denies all HTTP access to
 *     the entire `content/` tree.
 *
 * Filename uses a double-underscore separator (`<lang>__<slug>__<ts>`)
 * because a bare hyphen is allowed inside slugs. With lang fixed to
 * exactly 2 lowercase letters by the core's regex, there is no
 * ambiguity even if the slug itself contains underscores.
 *
 * No automatic purge — the operator triggers `purgeAll()` from the UI
 * when they want to reclaim space. This matches user expectation
 * ("Recycle Bin" / "Trash" semantics).
 */
final class Recycler
{
    private const RECYCLER_DIR  = '.recycler';
    private const TS_FORMAT     = 'Y-m-d_His';

    private string $recyclerDir;
    private string $contentRealDir;

    public function __construct(string $contentDir)
    {
        $contentReal = realpath($contentDir);
        if ($contentReal === false) {
            throw new \RuntimeException("Content directory not found: {$contentDir}");
        }
        $this->contentRealDir = $contentReal;
        $this->recyclerDir    = $contentReal . DIRECTORY_SEPARATOR . self::RECYCLER_DIR;
    }

    /**
     * Move <contentDir>/<lang>/<slug>.md into the recycler.
     *
     * Returns the recycler path on success, throws on failure.
     * Idempotent-ish: calling on a missing source throws — callers
     * are expected to check existence first (the controller already
     * loads the file before deciding to delete).
     */
    public function recycle(string $lang, string $slug): string
    {
        $this->assertSafeIdentifiers($lang, $slug);

        $sourcePath = $this->contentRealDir
            . DIRECTORY_SEPARATOR . $lang
            . DIRECTORY_SEPARATOR . $slug . '.md';
        $sourceReal = realpath($sourcePath);
        if ($sourceReal === false || !is_file($sourceReal)) {
            throw new \RuntimeException("Cannot recycle: source not found ({$lang}/{$slug}.md)");
        }
        if (!str_starts_with($sourceReal, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Recycle source escapes content root.");
        }

        $this->ensureDir($this->recyclerDir);
        // Defence in depth: refuse to move into a recycler that has
        // been replaced with a symlink pointing outside contentRealDir.
        // (The fixed `.recycler` segment cannot itself contain `..`,
        // but a malicious or accidental symlink remains possible.)
        $recyclerReal = realpath($this->recyclerDir);
        if ($recyclerReal === false) {
            throw new \RuntimeException("Cannot resolve recycler dir: {$this->recyclerDir}");
        }
        if (!str_starts_with($recyclerReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Recycler dir escapes content root.");
        }

        $target = $this->buildTargetPath($recyclerReal, $lang, $slug);

        // Use rename() — atomic on POSIX, preserves the original mtime
        // by default (good for "deleted at" forensics; the recycler
        // file's CTIME changes, the MTIME stays the original write time).
        if (!@rename($sourceReal, $target)) {
            throw new \RuntimeException("Failed to move file into recycler: {$sourceReal}");
        }
        @chmod($target, 0o600);
        return $target;
    }

    /**
     * Restore a soft-deleted page back to its original location.
     *
     * Steps:
     *   1. Validate $filename matches the recycler-filename pattern
     *      and resolve it under the realpath-contained recycler dir.
     *   2. Parse out the original (lang, slug); check the live
     *      content/<lang>/<slug>.md does NOT yet exist (refuse
     *      otherwise — operator must delete the current first).
     *   3. rename() the recycler file back to the original location.
     *
     * Returns ['lang' => $lang, 'slug' => $slug] for audit-logging.
     *
     * @return array{lang:string, slug:string}
     */
    public function restore(string $filename): array
    {
        $parsed = self::parseFilename($filename);
        if ($parsed === null) {
            throw new \InvalidArgumentException("Bad recycler filename: {$filename}");
        }

        $recyclerReal = $this->resolveRecyclerOrNull();
        if ($recyclerReal === null) {
            throw new \RuntimeException('Recycler is empty.');
        }
        $sourcePath = $recyclerReal . DIRECTORY_SEPARATOR . $filename;
        $sourceReal = realpath($sourcePath);
        if ($sourceReal === false || !is_file($sourceReal)) {
            throw new \RuntimeException("Recycler entry not found: {$filename}");
        }
        if (!str_starts_with($sourceReal, $recyclerReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Recycler entry escapes recycler root.');
        }

        $lang = $parsed['lang'];
        $slug = $parsed['slug'];
        $langDir = $this->contentRealDir . DIRECTORY_SEPARATOR . $lang;
        if (!is_dir($langDir) && !@mkdir($langDir, 0o755, true) && !is_dir($langDir)) {
            throw new \RuntimeException("Cannot create lang dir for restore: {$langDir}");
        }
        $langDirReal = realpath($langDir);
        if ($langDirReal === false
            || !str_starts_with($langDirReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException("Lang dir escapes content root: {$langDir}");
        }
        $target = $langDirReal . DIRECTORY_SEPARATOR . $slug . '.md';
        if (file_exists($target)) {
            throw new \RuntimeException(
                "Cannot restore: '{$lang}/{$slug}.md' already exists. Delete the current page first."
            );
        }

        if (!@rename($sourceReal, $target)) {
            throw new \RuntimeException("Restore rename failed: {$filename}");
        }
        @chmod($target, 0o644);

        return ['lang' => $lang, 'slug' => $slug];
    }

    /**
     * Delete recycler entries whose deletedAt timestamp is older than
     * `$days` days from now. Returns the count of files removed.
     *
     * Used by the auto-sweep that fires on backend access (after
     * login), so abandoned recycler files don't accumulate forever.
     * Pass $days = 0 to delete everything (equivalent to purgeAll).
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('purgeOlderThan: days must be >= 0.');
        }
        $cutoff = gmdate(self::TS_FORMAT, time() - ($days * 86400));
        $recyclerReal = $this->resolveRecyclerOrNull();
        if ($recyclerReal === null) {
            return 0;
        }
        $count = 0;
        foreach ((array)@scandir($recyclerReal) as $name) {
            if (!is_string($name)) continue;
            $parsed = self::parseFilename($name);
            if ($parsed === null) continue;
            // Lexical compare on Y-m-d_His is correct because the
            // format is zero-padded and big-endian.
            if (strcmp($parsed['deletedAt'], $cutoff) > 0) continue;
            $path = $recyclerReal . DIRECTORY_SEPARATOR . $name;
            $real = realpath($path);
            if ($real === false || !is_file($real)) continue;
            if (!str_starts_with($real, $recyclerReal . DIRECTORY_SEPARATOR)) continue;
            if (@unlink($real)) $count++;
        }
        return $count;
    }

    /**
     * Delete every file currently in the recycler. Returns the count
     * of files removed.
     *
     * Each file is realpath-resolved and required to land under the
     * realpath-resolved recyclerDir — so a symlink-targeted file
     * outside the recycler tree is left untouched (we delete only
     * what is genuinely inside the recycler).
     */
    public function purgeAll(): int
    {
        $recyclerReal = $this->resolveRecyclerOrNull();
        if ($recyclerReal === null) {
            return 0;
        }
        $count = 0;
        foreach ((array)@scandir($recyclerReal) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') {
                continue;
            }
            // Only purge .md files we ourselves wrote — defence-in-depth
            // against an operator dropping random files in here that
            // we'd then sweep.
            if (preg_match('/\.md$/', $name) !== 1) {
                continue;
            }
            $path = $recyclerReal . DIRECTORY_SEPARATOR . $name;
            $real = realpath($path);
            if ($real === false || !is_file($real)) {
                continue;
            }
            // Realpath-contain — refuse to delete a symlink target
            // that points outside the recycler.
            if (!str_starts_with($real, $recyclerReal . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (@unlink($real)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return list<array{filename:string, lang:string, slug:string, deletedAt:string}>
     *         Newest-first listing for the UI.
     */
    public function list(): array
    {
        $recyclerReal = $this->resolveRecyclerOrNull();
        if ($recyclerReal === null) {
            return [];
        }
        $out = [];
        foreach ((array)@scandir($recyclerReal) as $name) {
            if (!is_string($name)) {
                continue;
            }
            $parsed = self::parseFilename($name);
            if ($parsed === null) {
                continue;
            }
            $out[] = $parsed;
        }
        usort($out, static fn(array $a, array $b) => strcmp($b['deletedAt'], $a['deletedAt']));
        return $out;
    }

    /**
     * Resolve and containment-check the recycler directory. Returns
     * null when the directory does not exist (a fresh deploy with
     * nothing recycled yet). Throws if the directory exists but
     * resolves outside the content root — refuses to operate on a
     * misconfigured recycler rather than silently no-op'ing.
     */
    private function resolveRecyclerOrNull(): ?string
    {
        if (!is_dir($this->recyclerDir)) {
            return null;
        }
        $real = realpath($this->recyclerDir);
        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Recycler dir escapes content root: {$this->recyclerDir}");
        }
        return $real;
    }

    /**
     * Build the recycler filename. Collisions (same lang+slug+second)
     * are extremely unlikely given the recycler's expected volume,
     * but guarded with a sequence suffix for completeness.
     *
     * `$recyclerReal` is the realpath-resolved recycler directory —
     * the caller proves containment before passing it in.
     */
    private function buildTargetPath(string $recyclerReal, string $lang, string $slug): string
    {
        $ts = gmdate(self::TS_FORMAT);
        $base = $lang . '__' . $slug . '__' . $ts;
        $candidate = $recyclerReal . DIRECTORY_SEPARATOR . $base . '.md';
        if (!is_file($candidate)) {
            return $candidate;
        }
        for ($i = 1; $i < 100; $i++) {
            $candidate = $recyclerReal . DIRECTORY_SEPARATOR . $base . '_' . $i . '.md';
            if (!is_file($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Recycler filename collision space exhausted.');
    }

    /**
     * @return array{filename:string, lang:string, slug:string, deletedAt:string}|null
     */
    private static function parseFilename(string $name): ?array
    {
        // <lang>__<slug>__<Y-m-d_His>(_<seq>)?.md
        if (preg_match(
            '/^([a-z]{2})__([a-zA-Z][a-zA-Z0-9_-]{0,40})__([0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6})(?:_[0-9]+)?\.md$/',
            $name,
            $m
        ) !== 1) {
            return null;
        }
        return [
            'filename'  => $name,
            'lang'      => $m[1],
            'slug'      => $m[2],
            'deletedAt' => $m[3],
        ];
    }

    private function assertSafeIdentifiers(string $lang, string $slug): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create dir: {$dir}");
        }
    }
}
