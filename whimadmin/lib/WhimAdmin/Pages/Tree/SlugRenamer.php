<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Content\PageRepository;
use H42\WhimAdmin\Pages\RoutesUpdater;
use H42\WhimCMS\Content\Identifiers;

/**
 * Rename a slug across the three filesystem touchpoints that track it:
 *
 *   1. content/<lang>/<oldSlug>.md  →  content/<lang>/<newSlug>.md
 *   2. content/.history/<lang>/<oldSlug>/  →  content/.history/<lang>/<newSlug>/
 *   3. routes.<lang> value rewrite (in-memory only — caller commits)
 *
 * Mutates the in-memory routes table — the caller MUST call
 * `RoutesUpdater::commit()` afterwards so the change reaches disk.
 * The deferred commit lets callers batch multiple route mutations
 * (slug rename + URL change in the same save) into one atomic write.
 *
 * Records reverse-order rollback hooks on `$log` so a downstream
 * failure restores the .md / history / routes to their pre-call
 * state. Best-effort: cross-mount history-dir rename failures are
 * silenced (history is recoverable, not authoritative).
 *
 * Throws:
 *   - RuntimeException for user-actionable collisions (target slug
 *     already routed or already on disk)
 *   - TreeInternalException for filesystem failures
 *
 * No-op when $oldSlug === $newSlug.
 *
 * Extracted from TreeMutator::performSlugRename. Behaviour-identical.
 */
final class SlugRenamer
{
    public function __construct(
        private RoutesUpdater $routes,
        private PageRepository $pages,
        private string $contentRealDir,
    ) {
    }

    public function rename(
        string $lang,
        string $oldSlug,
        string $newSlug,
        TreeMutationLog $log,
    ): void {
        if ($oldSlug === $newSlug) {
            return;
        }
        Identifiers::assertSlug($newSlug);
        if ($this->routes->slugExists($lang, $newSlug)) {
            throw new \RuntimeException("Slug '{$newSlug}' already exists in language '{$lang}'.");
        }
        if ($this->pages->exists($lang, $newSlug)) {
            throw new \RuntimeException("Content file for slug '{$newSlug}' already exists.");
        }

        // 1. Rename .md (with cross-mount fallback).
        $oldMd = $this->contentRealDir . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $oldSlug . '.md';
        $newMd = $this->contentRealDir . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $newSlug . '.md';
        if (is_file($oldMd)) {
            if (!FsRename::safe($oldMd, $newMd)) {
                throw new TreeInternalException(
                    'Cannot rename the content file. Check filesystem permissions.',
                    ".md rename failed (native rename + copy+unlink fallback both refused): '{$oldMd}' → '{$newMd}'",
                );
            }
            $log->record(function () use ($oldMd, $newMd): void {
                if (is_file($newMd)) FsRename::safe($newMd, $oldMd);
            });
        }

        // 2. Rename history dir (best-effort).
        // Directories can't be cross-mount-renamed without a
        // recursive copy. We try native rename only; if it fails the
        // history under the OLD slug name becomes orphaned but the
        // .md (with new slug) is already in place. The operator can
        // move the history dir manually if needed; pages still save
        // and restore correctly with the (now-disconnected) history.
        $oldHistDir = $this->contentRealDir . DIRECTORY_SEPARATOR . '.history' . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $oldSlug;
        $newHistDir = $this->contentRealDir . DIRECTORY_SEPARATOR . '.history' . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $newSlug;
        if (is_dir($oldHistDir) && @rename($oldHistDir, $newHistDir)) {
            $log->record(function () use ($oldHistDir, $newHistDir): void {
                if (is_dir($newHistDir)) @rename($newHistDir, $oldHistDir);
            });
        }

        // 3. Routes rewrite — in-memory only; caller commits.
        $routesSnapshot = $this->routes->readAll();
        $this->routes->renameSlug($lang, $oldSlug, $newSlug);
        $log->record(function () use ($routesSnapshot): void {
            $this->routes->forceWrite($routesSnapshot);
        });
    }
}
