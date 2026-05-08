<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimCMS\Content\ContentNotFoundException;
use H42\WhimCMS\Content\Identifiers;
use H42\WhimCMS\Content\ParseException;

/**
 * Disk I/O for content pages.
 *
 * Single class for the four operations a Phase-3 editor needs:
 *
 *   load(lang, slug)            — read + parse to PageDocument
 *   save(lang, slug, doc)       — serialise + history-snapshot + atomic write
 *   delete(lang, slug)          — soft-delete via Recycler
 *   exists(lang, slug)          — quick presence check
 *
 * Every path operation goes through the same defence pattern as the
 * core's PageLoader:
 *
 *   1. Validate lang / slug against tight regexes (matches
 *      `H42\WhimCMS\Content\PageLoader` exactly so anything the
 *      public site can't load can't be saved here either).
 *   2. Build the candidate path under contentDir/<lang>/<slug>.md.
 *   3. realpath-contain the resolved path under contentDir before
 *      any read/write.
 *   4. Atomic write via tempfile + rename, mode 0o644 on the final
 *      file (Apache must be able to read it; the directory itself
 *      is 0o755).
 *
 * The save path also:
 *   - Triggers a HistoryStore snapshot of the pre-write content.
 *   - Re-parses the serialised output as a final integrity check —
 *     a corrupt save is rejected before bytes hit disk.
 *   - Validates UTF-8 + max-bytes against the same caps as the
 *     core's PageLoader (256 KiB default).
 *
 * No cache writes. The core's PageLoader invalidates its cache by
 * source mtime, and our atomic rename naturally bumps mtime, so
 * the next public-side request to the same URL re-renders.
 */
final class PageRepository
{
    private string $contentDir;
    private string $contentRealDir;

    public function __construct(
        string $contentDir,
        private HistoryStore $history,
        private Recycler $recycler,
        private int $maxBytes = 262144,
    ) {
        $real = realpath($contentDir);
        if ($real === false) {
            throw new \RuntimeException("Content directory not found: {$contentDir}");
        }
        $this->contentDir     = rtrim($contentDir, '/\\');
        $this->contentRealDir = $real;
        $this->maxBytes       = max(1024, $maxBytes);
    }

    public function exists(string $lang, string $slug): bool
    {
        $this->assertIdentifiers($lang, $slug);
        return is_file($this->pathFor($lang, $slug));
    }

    /**
     * @throws ContentNotFoundException when the file does not exist
     * @throws ParseException           when the file fails to parse
     * @throws \RuntimeException        on size, UTF-8, or path-escape errors
     */
    public function load(string $lang, string $slug): PageDocument
    {
        $this->assertIdentifiers($lang, $slug);
        $path = $this->pathFor($lang, $slug);
        $real = realpath($path);
        if ($real === false) {
            throw new ContentNotFoundException("Content file not found: {$lang}/{$slug}.md");
        }
        $this->assertContained($real);

        $size = @filesize($real);
        if ($size === false) {
            throw new \RuntimeException("Cannot stat content file: {$lang}/{$slug}.md");
        }
        if ($size > $this->maxBytes) {
            throw new \RuntimeException(
                'Content file exceeds maximum size of ' . $this->maxBytes . ' bytes: ' . $lang . '/' . $slug . '.md'
            );
        }
        $bytes = @file_get_contents($real);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read content file: {$lang}/{$slug}.md");
        }
        return PageDocument::fromSource($bytes);
    }

    /**
     * Persist a PageDocument. Steps:
     *   1. Validate identifiers + serialise the document.
     *   2. Round-trip the serialised bytes through the parser as a
     *      pre-write integrity check — a serialiser bug or stale
     *      attribute schema is caught here, never on disk.
     *   3. UTF-8 + size check.
     *   4. Take a HistoryStore snapshot of the current file (if any).
     *   5. Ensure the lang directory exists, contained under
     *      contentDir.
     *   6. Atomic write tempfile + rename to the final path.
     *
     * Returns the resolved final path (useful for audit log).
     *
     * @throws \RuntimeException on any I/O / containment / size failure
     * @throws ParseException    if the round-trip integrity check fails
     */
    public function save(string $lang, string $slug, PageDocument $doc): string
    {
        $this->assertIdentifiers($lang, $slug);
        $bytes = $doc->toSource();
        // Round-trip integrity check.
        PageDocument::fromSource($bytes);

        if (preg_match('//u', $bytes) !== 1) {
            throw new \RuntimeException('Serialised document is not valid UTF-8.');
        }
        $size = strlen($bytes);
        if ($size > $this->maxBytes) {
            throw new \RuntimeException(
                'Serialised document exceeds ' . $this->maxBytes . ' bytes (' . $size . ').'
            );
        }

        // Ensure lang directory.
        $langDir = $this->contentRealDir . DIRECTORY_SEPARATOR . $lang;
        if (!is_dir($langDir)) {
            if (!@mkdir($langDir, 0o755, true) && !is_dir($langDir)) {
                throw new \RuntimeException("Cannot create lang dir: {$langDir}");
            }
        }
        $langDirReal = realpath($langDir);
        if ($langDirReal === false) {
            throw new \RuntimeException("Cannot resolve lang dir: {$langDir}");
        }
        if (!str_starts_with($langDirReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Lang dir escapes content root: {$langDir}");
        }

        $target = $langDirReal . DIRECTORY_SEPARATOR . $slug . '.md';

        // History snapshot of pre-write contents.
        if (is_file($target)) {
            $this->history->snapshot($lang, $slug, $target);
        }

        // Atomic write.
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write content file (tempfile): {$target}");
        }
        @chmod($tmp, 0o644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot finalise content file: {$target}");
        }

        return $target;
    }

    /**
     * Soft-delete: move the file into the recycler. Returns the
     * recycler path so the audit log can record where it went.
     */
    public function delete(string $lang, string $slug): string
    {
        $this->assertIdentifiers($lang, $slug);
        if (!$this->exists($lang, $slug)) {
            throw new ContentNotFoundException("Cannot delete: not found ({$lang}/{$slug}.md)");
        }
        return $this->recycler->recycle($lang, $slug);
    }

    /**
     * @return list<array{lang:string, slug:string, mtime:int}>
     *         All published pages under contentDir, sorted by lang then slug.
     */
    public function listAll(): array
    {
        if (!is_dir($this->contentRealDir)) {
            return [];
        }
        $out = [];
        foreach ((array)@scandir($this->contentRealDir) as $langName) {
            if (!is_string($langName) || !Identifiers::isValidLang($langName)) {
                continue;
            }
            $langDir = $this->contentRealDir . DIRECTORY_SEPARATOR . $langName;
            if (!is_dir($langDir)) {
                continue;
            }
            foreach ((array)@scandir($langDir) as $fileName) {
                if (!is_string($fileName) || !str_ends_with($fileName, '.md')) {
                    continue;
                }
                $slug = substr($fileName, 0, -3);
                if (!Identifiers::isValidSlug($slug)) {
                    continue;
                }
                $mtime = @filemtime($langDir . DIRECTORY_SEPARATOR . $fileName);
                $out[] = [
                    'lang'  => $langName,
                    'slug'  => $slug,
                    'mtime' => $mtime === false ? 0 : $mtime,
                ];
            }
        }
        usort($out, static function (array $a, array $b): int {
            return [$a['lang'], $a['slug']] <=> [$b['lang'], $b['slug']];
        });
        return $out;
    }

    // ----- internals -----

    private function pathFor(string $lang, string $slug): string
    {
        return $this->contentDir . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $slug . '.md';
    }

    private function assertIdentifiers(string $lang, string $slug): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
    }

    private function assertContained(string $real): void
    {
        if (!str_starts_with($real, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Path escapes content root: {$real}");
        }
    }
}
