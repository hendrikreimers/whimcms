<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimCMS\Content\Identifiers;

/**
 * Per-page version-history store.
 *
 *   On every save through PageRepository, the PRE-WRITE bytes of the
 *   current `<contentDir>/<lang>/<slug>.md` are snapshotted to:
 *
 *     <contentDir>/.history/<lang>/<slug>/<utc-timestamp>.md
 *
 *   After the snapshot, history for that (lang, slug) is pruned to
 *   the most-recent N entries (configurable via app.php →
 *   content.history_max). Setting history_max to 0 disables history
 *   altogether — no `.history/` writes happen.
 *
 *   The `.history/` tree is invisible to the public site:
 *     - Its first segment is `.history`, which neither matches the
 *       core's lang regex (`/^[a-z]{2}$/`) nor any route slug, so
 *       PageLoader cannot reach it.
 *     - The bundled `content/.htaccess` denies all HTTP access to
 *       the entire `content/` tree.
 *
 * Listing + restore are intentionally NOT exposed in this Phase-2
 * primitive — the editor UI in Phase 4 will build on top of the
 * basic `snapshot()` + `prune()` API.
 *
 * Filename format: `<sortable-utc>.md` where the timestamp is
 *   `Y-m-d_His` in UTC. Sortable lexicographically, so a glob +
 *   array_sort gives chronological order.
 */
final class HistoryStore
{
    private const TS_FORMAT      = 'Y-m-d_His';
    private const HISTORY_DIR    = '.history';
    /**
     * Matches both the primary filename (`<ts>.md`) and the
     * collision-suffix variant (`<ts>_<n>.md`) emitted by `snapshot()`
     * when two saves land in the same UTC second. Without the optional
     * group, prune() would silently skip the suffixed files and history
     * would grow unbounded under heavy editing.
     */
    private const FILENAME_REGEX = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(?:_[0-9]+)?\.md$/';

    private string $historyDir;
    private string $contentRealDir;

    public function __construct(
        string $contentDir,
        private int $maxPerPage,
    ) {
        $contentReal = realpath($contentDir);
        if ($contentReal === false) {
            throw new \RuntimeException("Content directory not found: {$contentDir}");
        }
        $this->contentRealDir = $contentReal;
        $this->historyDir     = $contentReal . DIRECTORY_SEPARATOR . self::HISTORY_DIR;
        $this->maxPerPage     = max(0, $maxPerPage);
    }

    /**
     * Take a snapshot of the file at $sourcePath. Caller has already
     * realpath-contained $sourcePath under contentDir; we re-check
     * defensively and refuse anything outside the root.
     *
     * No-op when history is disabled (maxPerPage = 0) or the source
     * file does not exist (first-time create).
     */
    public function snapshot(string $lang, string $slug, string $sourcePath): void
    {
        if ($this->maxPerPage === 0) {
            return;
        }
        if (!is_file($sourcePath)) {
            return;
        }
        $this->assertSafeIdentifiers($lang, $slug);

        $sourceReal = realpath($sourcePath);
        if ($sourceReal === false) {
            return;
        }
        if (!str_starts_with($sourceReal, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("History snapshot source escapes content root: {$sourcePath}");
        }

        $pageDir = $this->pageDir($lang, $slug);
        if (!is_dir($pageDir) && !@mkdir($pageDir, 0o700, true) && !is_dir($pageDir)) {
            throw new \RuntimeException("Cannot create history dir: {$pageDir}");
        }
        // Defence in depth: even though pageDir is built from
        // assertSafeIdentifiers-validated lang/slug, a symlink at
        // .history/, .history/<lang>/, or .history/<lang>/<slug>/
        // could redirect the realpath outside contentRealDir. Refuse
        // to write there.
        $pageDirReal = realpath($pageDir);
        if ($pageDirReal === false) {
            throw new \RuntimeException("Cannot resolve history dir: {$pageDir}");
        }
        if (!str_starts_with($pageDirReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("History dir escapes content root: {$pageDir}");
        }

        $bytes = @file_get_contents($sourceReal);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read source for history snapshot: {$sourcePath}");
        }

        $target = $pageDirReal . DIRECTORY_SEPARATOR . gmdate(self::TS_FORMAT) . '.md';
        // Collision (same-second double save): suffix a sequence number.
        if (is_file($target)) {
            $i = 1;
            do {
                $candidate = preg_replace('/\.md$/', '_' . $i . '.md', $target) ?? $target;
                $i++;
            } while (is_file($candidate) && $i < 100);
            $target = $candidate;
        }

        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write history snapshot (tempfile): {$target}");
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot finalise history snapshot: {$target}");
        }

        $this->prune($lang, $slug);
    }

    /**
     * Drop history entries beyond the max for a (lang, slug) pair.
     * Public so a config-change to a smaller cap can be honoured by
     * UI tooling later.
     *
     * As in `snapshot()`, the page-dir is realpath-resolved and
     * containment-checked against contentRealDir before any unlink —
     * a symlink escape would otherwise let prune reach outside the
     * content tree.
     */
    public function prune(string $lang, string $slug): void
    {
        $this->assertSafeIdentifiers($lang, $slug);
        $pageDir = $this->pageDir($lang, $slug);
        if (!is_dir($pageDir)) {
            return;
        }
        $pageDirReal = realpath($pageDir);
        if ($pageDirReal === false) {
            return;
        }
        if (!str_starts_with($pageDirReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("History page dir escapes content root: {$pageDir}");
        }
        $entries = $this->listSorted($pageDirReal);
        $excess  = count($entries) - $this->maxPerPage;
        if ($excess <= 0) {
            return;
        }
        // Oldest-first deletion. listSorted returns descending; tail
        // is the oldest, so slice from there.
        $oldest = array_slice($entries, $this->maxPerPage);
        foreach ($oldest as $name) {
            @unlink($pageDirReal . DIRECTORY_SEPARATOR . $name);
        }
    }

    /**
     * Public listing of snapshots for one (lang, slug) pair, newest-
     * first. Returns enriched records suitable for rendering in the
     * editor's history view.
     *
     * @return list<array{filename:string, ts:string, mtime:int, size:int}>
     */
    public function listFor(string $lang, string $slug): array
    {
        $this->assertSafeIdentifiers($lang, $slug);
        $pageDir = $this->pageDir($lang, $slug);
        if (!is_dir($pageDir)) return [];
        $pageDirReal = realpath($pageDir);
        if ($pageDirReal === false) return [];
        if (!str_starts_with($pageDirReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            return [];
        }
        $out = [];
        foreach ($this->listSorted($pageDirReal) as $name) {
            $abs = $pageDirReal . DIRECTORY_SEPARATOR . $name;
            $out[] = [
                'filename' => $name,
                'ts'       => preg_replace('/\.md$/', '', $name) ?? $name,
                'mtime'    => (int)(@filemtime($abs) ?: 0),
                'size'     => (int)(@filesize($abs) ?: 0),
            ];
        }
        return $out;
    }

    /**
     * Read the raw bytes of one snapshot. Path is realpath-contained
     * under the page's history dir before any read.
     *
     * Returns null if the filename doesn't match the snapshot regex
     * or the file can't be resolved — the caller treats null as
     * "not found".
     */
    public function read(string $lang, string $slug, string $filename): ?string
    {
        $this->assertSafeIdentifiers($lang, $slug);
        if (preg_match(self::FILENAME_REGEX, $filename) !== 1) {
            return null;
        }
        $pageDir = $this->pageDir($lang, $slug);
        $pageDirReal = realpath($pageDir);
        if ($pageDirReal === false) return null;
        if (!str_starts_with($pageDirReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $abs = $pageDirReal . DIRECTORY_SEPARATOR . $filename;
        $real = realpath($abs);
        if ($real === false || !is_file($real)) return null;
        if (!str_starts_with($real, $pageDirReal . DIRECTORY_SEPARATOR)) return null;
        $bytes = @file_get_contents($real);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Sweep snapshots older than $days across ALL pages.
     *
     * Returns the count of files removed. Used by the auto-sweep on
     * backend access.
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('purgeOlderThan: days must be >= 0.');
        }
        if (!is_dir($this->historyDir)) {
            return 0;
        }
        $historyReal = realpath($this->historyDir);
        if ($historyReal === false) return 0;
        if (!str_starts_with($historyReal . DIRECTORY_SEPARATOR, $this->contentRealDir . DIRECTORY_SEPARATOR)) {
            return 0;
        }
        $cutoff = time() - ($days * 86400);
        $count = 0;
        // Walk <history>/<lang>/<slug>/*.md
        foreach ((array)@scandir($historyReal) as $langName) {
            if (!is_string($langName) || $langName === '.' || $langName === '..') continue;
            if (!Identifiers::isValidLang($langName)) continue;
            $langDir = $historyReal . DIRECTORY_SEPARATOR . $langName;
            if (!is_dir($langDir)) continue;
            foreach ((array)@scandir($langDir) as $slug) {
                if (!is_string($slug) || $slug === '.' || $slug === '..') continue;
                if (!Identifiers::isValidSlug($slug)) continue;
                $slugDir = $langDir . DIRECTORY_SEPARATOR . $slug;
                if (!is_dir($slugDir)) continue;
                foreach ((array)@scandir($slugDir) as $name) {
                    if (!is_string($name) || preg_match(self::FILENAME_REGEX, $name) !== 1) continue;
                    $abs = $slugDir . DIRECTORY_SEPARATOR . $name;
                    $mtime = @filemtime($abs);
                    if ($mtime === false || $mtime > $cutoff) continue;
                    if (@unlink($abs)) $count++;
                }
            }
        }
        return $count;
    }

    /**
     * @return list<string> Filenames, newest-first.
     */
    private function listSorted(string $pageDir): array
    {
        $entries = @scandir($pageDir);
        if ($entries === false) {
            return [];
        }
        $valid = [];
        foreach ($entries as $name) {
            if (preg_match(self::FILENAME_REGEX, $name) === 1) {
                $valid[] = $name;
            }
        }
        rsort($valid, SORT_STRING); // newest-first (ts strings are sortable)
        return $valid;
    }

    private function pageDir(string $lang, string $slug): string
    {
        return $this->historyDir
            . DIRECTORY_SEPARATOR . $lang
            . DIRECTORY_SEPARATOR . $slug;
    }

    /**
     * Match the core's PageLoader regex so a candidate that the
     * public site can't load also can't show up here.
     */
    private function assertSafeIdentifiers(string $lang, string $slug): void
    {
        Identifiers::assertLang($lang);
        Identifiers::assertSlug($slug);
    }
}
