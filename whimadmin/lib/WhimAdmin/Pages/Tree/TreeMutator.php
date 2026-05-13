<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Content\PageDocument;
use H42\WhimAdmin\Content\PageRepository;
use H42\WhimAdmin\Content\Recycler;
use H42\WhimAdmin\Pages\OverlayWriter;
use H42\WhimAdmin\Pages\RoutesUpdater;
use H42\WhimCMS\Content\Identifiers;

/**
 * The single mutation surface for the page tree.
 *
 * One method per logical operation (create/move/rename/retype/delete/save).
 * Each method:
 *
 *   1. Verifies the supplied `treeVersion` against current state —
 *      rejects with a typed exception so the controller can return 409.
 *   2. Loads the affected overlay (one language) and the routes table.
 *   3. Mutates in-memory.
 *   4. Walks an internal transaction log so multi-file commits can
 *      roll back as far as possible if a later step fails.
 *
 * Write order across a multi-step commit (e.g. slug rename):
 *
 *   .md rename  →  history-dir rename  →  routes commit  →  overlay write
 *
 * The overlay write is intentionally last — until that point the
 * public site is in a consistent (if temporarily mid-rename) state.
 * If anything fails, the rollback list reverses the steps that
 * already completed.
 *
 * Implementation factoring: the per-operation bodies orchestrate
 * specialised classes that own each side-concern. The Mutator itself
 * keeps responsibility for the lock + version verification + audit-
 * relevant return shape.
 *
 *   SlugAllocator         random unused slug for fresh pages
 *   SlugRenamer           .md + history-dir + routes-rewrite (deferred commit)
 *   HomeSlugGuard         refuse delete/retype-away-from-slug on home
 *   UnsortedSlugResolver  resolve slug from synthetic Unsorted-bucket position
 *   RoutesBucketApplier   slug-rename + URL-change inside save (one batch)
 *   FrontmatterBucketApplier  .md front-matter write
 *   OverlayBucketApplier  overlay item update + cascade trigger
 *   UrlDeriver            parent-segment derivation + cascade walk
 *   Indexing              pure tree-array index manipulation
 *   FsRename              cross-mount-safe rename helper
 *
 * Slug collisions inside one language fail loud with a
 * RuntimeException; the random-slug helper for `create()` retries
 * internally so a fresh creation cannot 409 on a random collision.
 * Cross-language slug moves are not supported — the controller layer
 * rejects them; here we defensively assume single-language operations
 * only.
 */
final class TreeMutator
{
    /** Lock filename inside whimadmin/var/state — single point of
     *  serialisation for all tree mutations across requests. */
    private const LOCK_FILENAME = 'tree-mutation.lock';

    private SlugAllocator $slugAllocator;
    private SlugRenamer $slugRenamer;
    private HomeSlugGuard $homeGuard;
    private UnsortedSlugResolver $unsortedResolver;
    private RoutesBucketApplier $routesBucket;
    private FrontmatterBucketApplier $frontmatterBucket;
    private OverlayBucketApplier $overlayBucket;

    /**
     * @param list<string> $allowedOverlaySections  i18n_overlay.allowed_sections
     * @param list<string> $configuredSections      i18n_overlay.page_tree.sections — every
     *        mutation outside `unsorted` must target one of these. Without this allowlist
     *        an authenticated request could persist data under arbitrary section keys
     *        (e.g. `navigation.evil`); the aggregator wouldn't display them, but the
     *        bytes would still land in `_i18n_overlay.<lang>.json`. The OverlayWriter
     *        accepts any shape-valid key, so this is the canonical gate.
     */
    public function __construct(
        private OverlayWriter   $overlayWriter,
        private RoutesUpdater   $routes,
        private PageRepository  $pages,
        private Recycler        $recycler,
        private UrlDeriver      $urls,              // parent-segment + cascade
        private string          $contentDir,        // <core>/content (already validated by Kernel)
        private string          $contentRealDir,    // realpath of contentDir
        private string          $treeRoot,
        private array           $allowedOverlaySections,
        private array           $configuredSections,
        private TreeAggregator  $aggregator,        // for version verification
        private string          $stateDir,          // whimadmin/var/state — lockfile lives here
    ) {
        // Compose the specialised helpers. All-internal — Kernel only
        // wires the primitives above; the orchestration objects are
        // private to the mutator.
        $this->slugAllocator     = new SlugAllocator($this->routes, $this->pages);
        $this->slugRenamer       = new SlugRenamer($this->routes, $this->pages, $this->contentRealDir);
        $this->unsortedResolver  = new UnsortedSlugResolver($this->aggregator);
        $this->homeGuard         = new HomeSlugGuard($this->routes, $this->overlayWriter, $this->unsortedResolver, $this->treeRoot);
        $this->routesBucket      = new RoutesBucketApplier($this->slugRenamer, $this->routes, $this->unsortedResolver, $this->overlayWriter, $this->treeRoot);
        $this->frontmatterBucket = new FrontmatterBucketApplier($this->pages);
        $this->overlayBucket     = new OverlayBucketApplier($this->overlayWriter, $this->routes, $this->urls, $this->treeRoot, $this->allowedOverlaySections);
    }

    /**
     * Serialise all tree mutations through a process-wide exclusive
     * lock. Without this the optimistic-locking version check is
     * TOCTOU-vulnerable: two parallel requests could both pass
     * verifyVersion() against the same version, then both commit,
     * silently overwriting each other.
     *
     * The lock spans verifyVersion + mutation + commit + the version
     * compute that lands in the response, so by the time the lock
     * releases the on-disk state and the returned version are
     * consistent. A second request waiting on the lock proceeds with
     * fresh state and either succeeds or surfaces a 409.
     *
     * Lock acquisition failure (FS unwritable, OS limit) fails loud —
     * the operator sees a clear "cannot acquire tree lock" rather than
     * a silent skip of the safety net.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withLock(callable $fn): mixed
    {
        $lockPath = $this->stateDir . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new TreeInternalException(
                'Cannot acquire tree-mutation lock. Check filesystem permissions.',
                "fopen failed for '{$lockPath}'",
            );
        }
        @chmod($lockPath, 0o600);
        if (!flock($handle, LOCK_EX)) {
            @fclose($handle);
            throw new TreeInternalException(
                'Cannot lock tree for mutation.',
                'flock(LOCK_EX) failed',
            );
        }
        try {
            return $fn();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    // ============================================================
    // CREATE
    // ============================================================

    /**
     * Create a new tree node.
     *
     * For `type=slug`:
     *   - allocates a random slug (Identifiers::SLUG_PATTERN-conformant)
     *   - writes an empty .md stub with disabled=true in front-matter
     *   - adds a routes.php entry using the slug as the URL (the editor
     *     promptly renames the URL via /save)
     *   - inserts the overlay item with hidden=true
     *
     * For href/anchor/folder:
     *   - inserts the overlay item with hidden=true; no .md, no route
     *
     * @return array{slug:?string, indexPath:string}
     */
    public function create(
        string $lang,
        string $section,
        string $parentIndexPath,
        int    $beforeIndex,
        string $type,
        string $expectedVersion,
    ): array {
        return $this->withLock(fn() => $this->createImpl(
            $lang, $section, $parentIndexPath, $beforeIndex, $type, $expectedVersion,
        ));
    }

    private function createImpl(
        string $lang,
        string $section,
        string $parentIndexPath,
        int    $beforeIndex,
        string $type,
        string $expectedVersion,
    ): array {
        $this->assertSection($section);
        $this->verifyVersion($expectedVersion);

        $overlay = $this->overlayWriter->read($lang);
        $rootKey = $this->treeRoot;
        $overlay[$rootKey] ??= [];
        if (!is_array($overlay[$rootKey])) {
            throw new TreeInternalException(
                'The page tree appears corrupted — please reload and retry.',
                "overlay '{$rootKey}' is not an object",
            );
        }
        $overlay[$rootKey][$section] ??= [];
        if (!is_array($overlay[$rootKey][$section])) {
            throw new TreeInternalException(
                'The page tree appears corrupted — please reload and retry.',
                "overlay '{$rootKey}.{$section}' is not an array",
            );
        }

        $log  = new TreeMutationLog();
        $slug = null;

        try {
            $item = $this->buildBlankItem($type);

            // type=slug: allocate slug, create .md, add route.
            if ($type === 'slug') {
                $slug = $this->slugAllocator->allocate($lang);
                $item['slug'] = $slug;

                // Write .md stub. PageRepository::save handles atomic
                // write + round-trip integrity check + history snapshot
                // (no snapshot taken on a fresh save because the file
                // didn't exist yet).
                $doc = new PageDocument(
                    header: ['layout' => 'default', 'disabled' => 'true'],
                    blocks: [],
                );
                $writtenMdPath = $this->pages->save($lang, $slug, $doc);
                $log->record(function () use ($writtenMdPath): void {
                    if (is_file($writtenMdPath)) {
                        @unlink($writtenMdPath);
                    }
                });

                // Initial URL reflects the new parent — same derivation
                // as move(): slug → parent.url, anchor → anchor-id,
                // folder/href → kebab(parent.label). Root level → flat
                // URL = slug.
                $prefix = $this->urls->deriveParentSegment(
                    $overlay, $section, $parentIndexPath, $lang,
                );
                $initialUrl = UrlDeriver::composeUrl($prefix, $slug);
                $this->routes->addEntry($lang, $initialUrl, $slug);
                $this->routes->commit();
                $log->record(function () use ($lang, $slug): void {
                    $this->routes->reset();
                    $this->routes->removeBySlug($lang, $slug);
                    $this->routes->commit();
                });
            }

            // Insert overlay item.
            $sectionItems = $overlay[$rootKey][$section];
            $sectionItems = Indexing::spliceIn($sectionItems, $parentIndexPath, $beforeIndex, $item);
            $overlay[$rootKey][$section] = $sectionItems;
            $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);

            // Compute resulting indexPath from the splice operation.
            $resultPath = Indexing::pathAfterInsert($parentIndexPath, $beforeIndex, $sectionItems);

            return ['slug' => $slug, 'indexPath' => $resultPath];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    // ============================================================
    // MOVE
    // ============================================================

    /**
     * Move an existing node within the same language.
     *
     * Cross-section moves are allowed (e.g. main → footer). Cross-
     * language moves are NOT — pages are language-bound.
     */
    public function move(
        string $lang,
        string $fromSection,
        string $fromIndexPath,
        string $toSection,
        string $toParentIndexPath,
        int    $toBeforeIndex,
        string $expectedVersion,
    ): array {
        return $this->withLock(fn() => $this->moveImpl(
            $lang, $fromSection, $fromIndexPath, $toSection, $toParentIndexPath, $toBeforeIndex, $expectedVersion,
        ));
    }

    private function moveImpl(
        string $lang,
        string $fromSection,
        string $fromIndexPath,
        string $toSection,
        string $toParentIndexPath,
        int    $toBeforeIndex,
        string $expectedVersion,
    ): array {
        $this->assertSection($fromSection);
        $this->assertSection($toSection);
        if ($fromSection === 'unsorted' && $toSection === 'unsorted') {
            // Reordering inside the synthetic bucket is meaningless
            // — its order is derived from routes.php iteration.
            throw new \RuntimeException("Cannot reorder inside the 'unsorted' bucket.");
        }
        $this->verifyVersion($expectedVersion);

        // Three flavours of move involving the synthetic unsorted bucket:
        //
        //   unsorted → section : promote an orphaned routed slug into
        //                        the navigation. No spliceOut (no overlay
        //                        entry to remove); just spliceIn a
        //                        new {slug: <slug>} item at the target.
        //                        URL auto-prefix applies via the parent.
        //
        //   section → unsorted : demote an item out of the navigation.
        //                        SpliceOut from source section; no
        //                        spliceIn (unsorted is route-derived).
        //                        The slug + routes-entry + .md all
        //                        survive — the page just disappears
        //                        from the nav.
        //
        //   section → section  : the original case — spliceOut +
        //                        spliceIn + URL re-prefix when slug-typed.
        if ($fromSection === 'unsorted') {
            return $this->moveFromUnsorted($lang, $fromIndexPath, $toSection, $toParentIndexPath, $toBeforeIndex);
        }
        if ($toSection === 'unsorted') {
            return $this->moveToUnsorted($lang, $fromSection, $fromIndexPath);
        }
        // Fall-through: regular section ↔ section.

        $overlay = $this->overlayWriter->read($lang);
        $rootKey = $this->treeRoot;
        if (!is_array($overlay[$rootKey] ?? null)) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "overlay '{$rootKey}' missing",
            );
        }
        if (!is_array($overlay[$rootKey][$fromSection] ?? null)) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "source section '{$fromSection}' missing",
            );
        }
        $overlay[$rootKey][$toSection] ??= [];
        if (!is_array($overlay[$rootKey][$toSection])) {
            throw new TreeInternalException(
                'The page tree appears corrupted — please reload and retry.',
                "target section '{$toSection}' is not an array",
            );
        }

        // Refuse drop-into-self and drop-into-descendant before any
        // mutation. The client also blocks these, but a malicious or
        // stale POST could send them — fail-loud here is the canonical
        // gate.
        if ($fromSection === $toSection) {
            if ($fromIndexPath === $toParentIndexPath) {
                throw new \RuntimeException("Cannot drop a page into itself.");
            }
            if (str_starts_with($toParentIndexPath, $fromIndexPath . '/')) {
                throw new \RuntimeException("Cannot drop a page into its own descendant.");
            }
        }

        [$item, $newFromItems] = Indexing::spliceOut($overlay[$rootKey][$fromSection], $fromIndexPath);
        $overlay[$rootKey][$fromSection] = $newFromItems;

        // Adjustments for same-section moves: spliceOut shifts every
        // index that sat AFTER the source in the same parent-array.
        // Two corrections needed before spliceIn:
        //   (a) target's parent-path may have referenced the source's
        //       parent at a depth where indices shifted — rewrite the
        //       toParentIndexPath segments accordingly.
        //   (b) for same-parent reorder, toBeforeIndex itself shifts
        //       when the source was BEFORE the target position.
        if ($fromSection === $toSection) {
            $toParentIndexPath = Indexing::adjustPathAfterSplice($fromIndexPath, $toParentIndexPath);

            $fromParent = Indexing::parentOfIndexPath($fromIndexPath);
            $fromLeaf   = Indexing::leafOfIndexPath($fromIndexPath);
            if ($fromParent === $toParentIndexPath && $fromLeaf < $toBeforeIndex) {
                $toBeforeIndex--;
            }
        }

        $newToItems = Indexing::spliceIn(
            $overlay[$rootKey][$toSection],
            $toParentIndexPath,
            $toBeforeIndex,
            $item,
        );
        $overlay[$rootKey][$toSection] = $newToItems;

        // If the moved item is a routed slug, its URL prefix reflects
        // its new parent. The parent-segment derivation matches the
        // create() path so the two operations stay consistent.
        $log = new TreeMutationLog();
        try {
            $newUrl = null;
            if (is_string($item['slug'] ?? null)) {
                $prefix = $this->urls->deriveParentSegment(
                    $overlay, $toSection, $toParentIndexPath, $lang,
                );
                $newUrl = UrlDeriver::composeUrl($prefix, $this->routes->urlForSlug($lang, $item['slug']) ?? $item['slug']);
                $currentUrl = $this->routes->urlForSlug($lang, $item['slug']);
                if ($newUrl !== $currentUrl) {
                    $routesSnapshot = $this->routes->readAll();
                    // changeUrl throws on collision — bubble up with a
                    // clear message; the move overlay isn't on disk yet.
                    $this->routes->changeUrl($lang, $item['slug'], $newUrl);
                    $this->routes->commit();
                    $log->record(function () use ($routesSnapshot): void {
                        $this->routes->forceWrite($routesSnapshot);
                    });
                }
            }

            // Cascade-rename children of the moved item: their URL
            // prefix derives from the moved item, whose own segment
            // (label / anchor / url) may now produce a different
            // composed URL because it sits under a different parent.
            // `UrlDeriver::cascade` mutates the routes table in-memory
            // only — we must commit() afterwards or the child URL
            // changes never reach disk.
            $resultPath = Indexing::pathAfterInsert($toParentIndexPath, $toBeforeIndex, $newToItems);
            $this->urls->cascade($lang, $overlay, $toSection, $resultPath, $log);
            $this->routes->commit();

            $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);

            return ['indexPath' => $resultPath, 'url' => $newUrl];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    /**
     * Promote a routed-but-unreferenced slug from the Unsorted bucket
     * into a configured section. The routes-entry stays; we add a
     * fresh overlay item that references it. URL gets the parent-
     * segment auto-prefix so the slug lands at the expected URL path.
     */
    private function moveFromUnsorted(
        string $lang,
        string $fromIndexPath,
        string $toSection,
        string $toParentIndexPath,
        int    $toBeforeIndex,
    ): array {
        $slug = $this->unsortedResolver->resolve($lang, $fromIndexPath);
        if ($slug === null) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "unsorted entry at '{$fromIndexPath}' not found",
            );
        }

        $overlay = $this->overlayWriter->read($lang);
        $rootKey = $this->treeRoot;
        $overlay[$rootKey] ??= [];
        $overlay[$rootKey][$toSection] ??= [];
        if (!is_array($overlay[$rootKey][$toSection])) {
            throw new TreeInternalException(
                'The page tree appears corrupted — please reload and retry.',
                "target section '{$toSection}' is not an array",
            );
        }

        // Fresh overlay item: slug-ref + label defaults to slug name.
        // Hidden=false because the user explicitly placed it in nav.
        $newItem = [
            'label' => $slug,
            'slug'  => $slug,
        ];
        $newToItems = Indexing::spliceIn(
            $overlay[$rootKey][$toSection],
            $toParentIndexPath,
            $toBeforeIndex,
            $newItem,
        );
        $overlay[$rootKey][$toSection] = $newToItems;

        // URL re-prefix to reflect the new position in the nav.
        $log = new TreeMutationLog();
        try {
            $prefix = $this->urls->deriveParentSegment($overlay, $toSection, $toParentIndexPath, $lang);
            $currentUrl = $this->routes->urlForSlug($lang, $slug);
            $newUrl = UrlDeriver::composeUrl($prefix, $currentUrl ?? $slug);
            if ($newUrl !== $currentUrl) {
                $routesSnapshot = $this->routes->readAll();
                $this->routes->changeUrl($lang, $slug, $newUrl);
                $this->routes->commit();
                $log->record(function () use ($routesSnapshot): void {
                    $this->routes->forceWrite($routesSnapshot);
                });
            }
            $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);
            $resultPath = Indexing::pathAfterInsert($toParentIndexPath, $toBeforeIndex, $newToItems);
            return ['indexPath' => $resultPath, 'slug' => $slug, 'url' => $newUrl];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    /**
     * Demote a routed slug out of the navigation. Just splice the
     * overlay entry out — routes + .md stay (the page is still
     * reachable at its URL, it just no longer appears in nav).
     * Slug-less items (folder/anchor/href without slug) cannot be
     * "demoted to unsorted" since unsorted is route-derived; refused
     * loudly so the UI doesn't pretend it worked.
     */
    private function moveToUnsorted(
        string $lang,
        string $fromSection,
        string $fromIndexPath,
    ): array {
        $overlay = $this->overlayWriter->read($lang);
        $rootKey = $this->treeRoot;
        if (!is_array($overlay[$rootKey][$fromSection] ?? null)) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "source section '{$fromSection}' missing",
            );
        }
        $item = Indexing::locateItem($overlay[$rootKey][$fromSection], $fromIndexPath);
        $slug = is_string($item['slug'] ?? null) ? $item['slug'] : null;
        if ($slug === null) {
            throw new \RuntimeException(
                "Only routed pages can be moved to the 'Unsorted' bucket. "
                . "External links, anchors, and folders live only in the navigation overlay; "
                . "delete them from the context menu instead."
            );
        }

        [, $newSectionItems] = Indexing::spliceOut($overlay[$rootKey][$fromSection], $fromIndexPath);
        $overlay[$rootKey][$fromSection] = $newSectionItems;
        $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);

        // URL is intentionally NOT re-prefixed here — moving out of
        // the nav is a structural change, not a re-routing. The slug
        // keeps the URL it had; if the user later promotes it back
        // into a section, that move re-applies the auto-prefix.
        return ['slug' => $slug];
    }

    // ============================================================
    // RENAME (slug only)
    // ============================================================

    /**
     * Rename the slug for a `type=slug` node.
     *
     * Side effects within one language:
     *   - content/<lang>/<old>.md     → content/<lang>/<new>.md
     *   - content/.history/<lang>/<old>/ → content/.history/<lang>/<new>/
     *   - routes.<lang> value rewrite
     *   - overlay item slug-field rewrite (everywhere in the lang's tree)
     */
    public function rename(
        string $lang,
        string $section,
        string $indexPath,
        string $newSlug,
        string $expectedVersion,
    ): array {
        return $this->withLock(fn() => $this->renameImpl(
            $lang, $section, $indexPath, $newSlug, $expectedVersion,
        ));
    }

    private function renameImpl(
        string $lang,
        string $section,
        string $indexPath,
        string $newSlug,
        string $expectedVersion,
    ): array {
        $this->assertSection($section);
        Identifiers::assertSlug($newSlug);
        $this->verifyVersion($expectedVersion);

        $overlay = $this->overlayWriter->read($lang);
        $rootKey = $this->treeRoot;

        if ($section !== 'unsorted') {
            if (!is_array($overlay[$rootKey][$section] ?? null)) {
                throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "section '{$section}' missing in overlay '{$this->treeRoot}'",
            );
            }
            $item = Indexing::locateItem($overlay[$rootKey][$section], $indexPath);
            $oldSlug = is_string($item['slug'] ?? null) ? $item['slug'] : null;
            if ($oldSlug === null) {
                throw new \RuntimeException("Selected item is not a slug-type page — rename is only available for routed pages.");
            }
        } else {
            // For unsorted, indexPath addresses a synthetic position
            // among the routes-only slugs. We resolve to the slug
            // string via the aggregator's current view.
            $oldSlug = $this->unsortedResolver->resolve($lang, $indexPath);
            if ($oldSlug === null) {
                throw new TreeInternalException(
                    'The page tree changed since you last viewed it — please reload and retry.',
                    "unsorted entry at '{$indexPath}' not found",
                );
            }
        }

        if ($oldSlug === $newSlug) {
            return ['indexPath' => $indexPath]; // no-op
        }

        $log = new TreeMutationLog();
        try {
            // Steps 1-3 (.md + history dir + routes) delegated to
            // SlugRenamer — same primitives that saveImpl reuses when
            // its routes-bucket carries a slug change. SlugRenamer
            // does its own collision pre-checks (slugExists + .md
            // exists) and throws RuntimeException with the same
            // user-facing wording as before the extraction.
            $this->slugRenamer->rename($lang, $oldSlug, $newSlug, $log);
            $this->routes->commit();

            // 4. Update overlay: rewrite every {slug: $oldSlug} → {slug: $newSlug}.
            SlugRefRewriter::rewriteSlugRefs($overlay, $oldSlug, $newSlug);
            $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);

            return ['indexPath' => $indexPath, 'slug' => $newSlug];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    // ============================================================
    // RETYPE
    // ============================================================

    /**
     * Change the type of a tree node. Combinations and side effects:
     *
     *   slug → href/anchor/folder    .md recycled, route removed,
     *                                overlay shape rewritten
     *   href/anchor/folder → slug    new random slug + .md stub +
     *                                routes entry, overlay shape rewritten
     *   between non-slug types       overlay shape only
     */
    public function retype(
        string $lang,
        string $section,
        string $indexPath,
        string $newType,
        string $expectedVersion,
    ): array {
        return $this->withLock(fn() => $this->retypeImpl(
            $lang, $section, $indexPath, $newType, $expectedVersion,
        ));
    }

    private function retypeImpl(
        string $lang,
        string $section,
        string $indexPath,
        string $newType,
        string $expectedVersion,
    ): array {
        $this->assertSection($section);
        if (!in_array($newType, ['slug', 'href', 'anchor', 'folder'], true)) {
            throw new \RuntimeException("Unknown page type: '{$newType}'.");
        }
        $this->verifyVersion($expectedVersion);
        // Retyping the home-slug away from `slug` would orphan the
        // `routes.<lang>[''] = home` mapping and break the public
        // brand link in nav-core.html. Refuse.
        if ($newType !== 'slug') {
            $this->homeGuard->refuse($lang, $section, $indexPath);
        }

        $overlay = $this->overlayWriter->read($lang);
        $rootKey = $this->treeRoot;
        if (!is_array($overlay[$rootKey][$section] ?? null)) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "section '{$section}' missing in overlay '{$this->treeRoot}'",
            );
        }
        $item = Indexing::locateItem($overlay[$rootKey][$section], $indexPath);
        $oldType = self::deriveItemType($item);
        if ($oldType === $newType) {
            return ['indexPath' => $indexPath];
        }

        $log = new TreeMutationLog();
        try {
            $newSlug = null;
            $oldSlug = is_string($item['slug'] ?? null) ? $item['slug'] : null;

            // Strip type-discriminator fields, keep label / hidden /
            // children. Rebuild from a clean base.
            $newItem = [];
            if (isset($item['label']))    $newItem['label']    = $item['label'];
            if (isset($item['hidden']))   $newItem['hidden']   = $item['hidden'];
            if (isset($item['children'])) $newItem['children'] = $item['children'];

            // Old-type side effects (cleanup).
            if ($oldType === 'slug' && $oldSlug !== null) {
                if ($this->pages->exists($lang, $oldSlug)) {
                    try {
                        $recycledPath = $this->recycler->recycle($lang, $oldSlug);
                    } catch (\Throwable $e) {
                        // Recycler::recycle may fail on cross-device
                        // renames (overlayfs, mounted bind, etc.). The
                        // underlying message includes absolute paths
                        // we don't want surfaced to the client in
                        // production — re-throw via TreeInternalException
                        // so the debug-gate filters it.
                        throw new TreeInternalException(
                            'Cannot recycle the content file. Check filesystem permissions or storage layout.',
                            'recycler failure: ' . $e->getMessage(),
                        );
                    }
                    $log->record(function () use ($recycledPath, $lang, $oldSlug): void {
                        // Best-effort: restore from recycler back to live.
                        if (is_file($recycledPath)) {
                            $base = $this->contentRealDir . DIRECTORY_SEPARATOR . $lang;
                            @rename($recycledPath, $base . DIRECTORY_SEPARATOR . $oldSlug . '.md');
                        }
                    });
                }
                $routesSnapshot = $this->routes->readAll();
                $this->routes->removeBySlug($lang, $oldSlug);
                $this->routes->commit();
                $log->record(function () use ($routesSnapshot): void {
                    $this->routes->forceWrite($routesSnapshot);
                });
            }

            // New-type side effects (creation).
            if ($newType === 'slug') {
                $newSlug = $this->slugAllocator->allocate($lang);
                $newItem['slug'] = $newSlug;
                $doc = new PageDocument(
                    header: ['layout' => 'default', 'disabled' => 'true'],
                    blocks: [],
                );
                $mdPath = $this->pages->save($lang, $newSlug, $doc);
                $log->record(function () use ($mdPath): void {
                    if (is_file($mdPath)) @unlink($mdPath);
                });
                $this->routes->addEntry($lang, $newSlug, $newSlug);
                $this->routes->commit();
                $log->record(function () use ($lang, $newSlug): void {
                    $this->routes->reset();
                    $this->routes->removeBySlug($lang, $newSlug);
                    $this->routes->commit();
                });
            } elseif ($newType === 'href') {
                $newItem['href'] = '#';
            } elseif ($newType === 'anchor') {
                $newItem['anchor'] = 'anchor';
            } elseif ($newType === 'folder') {
                // OverlayWriter requires every non-slug/href/anchor
                // item to carry a children array — even an empty one.
                // Without this default a retype slug → folder produces
                // an item missing all discriminators and the writer
                // rejects it.
                if (!isset($newItem['children'])) {
                    $newItem['children'] = [];
                }
            }

            // Update overlay.
            Indexing::replaceItem($overlay[$rootKey][$section], $indexPath, $newItem);

            // Cascade: a retype shifts the item's parent-segment
            // (slug → folder/href flips from URL-based to kebab-label;
            // folder → slug introduces a routes-URL where none was).
            // Any slug children inherit a new prefix. Routes-commit
            // mandatory after — cascade mutates in-memory only.
            $this->urls->cascade($lang, $overlay, $section, $indexPath, $log);
            $this->routes->commit();

            $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);

            return ['indexPath' => $indexPath, 'slug' => $newSlug, 'oldType' => $oldType, 'newType' => $newType];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    // ============================================================
    // DELETE
    // ============================================================

    /**
     * Remove a node from the overlay. For type=slug, also recycle the
     * .md and remove the routes entry. History is preserved (the
     * Recycler stores the .md under its original name; the history dir
     * remains in place for potential restore).
     *
     * Deleting from `unsorted`: only routes + .md, since the entry is
     * not in the overlay to begin with.
     */
    public function delete(
        string $lang,
        string $section,
        string $indexPath,
        string $expectedVersion,
    ): array {
        return $this->withLock(fn() => $this->deleteImpl(
            $lang, $section, $indexPath, $expectedVersion,
        ));
    }

    private function deleteImpl(
        string $lang,
        string $section,
        string $indexPath,
        string $expectedVersion,
    ): array {
        $this->assertSection($section);
        $this->verifyVersion($expectedVersion);
        $this->homeGuard->refuse($lang, $section, $indexPath);

        $log = new TreeMutationLog();
        try {
            // Phase A: resolve target + plan the overlay mutation
            // in-memory. The overlay write is intentionally the LAST
            // disk side-effect — if recycler or routes fail, the
            // overlay file stays untouched and the tree reflects an
            // unchanged state on next refresh. The previous order
            // (overlay first, then recycle/routes) could leave a
            // page-tree showing as "deleted" in the editor while
            // routes.php + .md still referenced it (visible as a
            // phantom in Unsorted after the failed delete).
            $slug = null;
            $overlay = null;
            $newSectionItems = null;
            if ($section === 'unsorted') {
                $slug = $this->unsortedResolver->resolve($lang, $indexPath);
                if ($slug === null) {
                    throw new TreeInternalException(
                        'The page tree changed since you last viewed it — please reload and retry.',
                        "unsorted entry at '{$indexPath}' not found",
                    );
                }
            } else {
                $overlay = $this->overlayWriter->read($lang);
                $rootKey = $this->treeRoot;
                if (!is_array($overlay[$rootKey][$section] ?? null)) {
                    throw new TreeInternalException(
                        'The page tree changed since you last viewed it — please reload and retry.',
                        "section '{$section}' missing in overlay '{$this->treeRoot}'",
                    );
                }
                $item = Indexing::locateItem($overlay[$rootKey][$section], $indexPath);
                $slug = is_string($item['slug'] ?? null) ? $item['slug'] : null;

                [, $newSectionItems] = Indexing::spliceOut($overlay[$rootKey][$section], $indexPath);
                $overlay[$rootKey][$section] = $newSectionItems;
                // Overlay NOT written yet — defer to Phase C.
            }

            // Phase B: filesystem side effects (recycle .md + routes).
            if ($slug !== null) {
                if ($this->pages->exists($lang, $slug)) {
                    try {
                        $recycledPath = $this->recycler->recycle($lang, $slug);
                    } catch (\Throwable $e) {
                        throw new TreeInternalException(
                            'Cannot recycle the content file. Check filesystem permissions or storage layout.',
                            'recycler failure: ' . $e->getMessage(),
                        );
                    }
                    $log->record(function () use ($recycledPath, $lang, $slug): void {
                        if (is_file($recycledPath)) {
                            $base = $this->contentRealDir . DIRECTORY_SEPARATOR . $lang;
                            @rename($recycledPath, $base . DIRECTORY_SEPARATOR . $slug . '.md');
                        }
                    });
                }
                $routesSnapshot = $this->routes->readAll();
                $this->routes->removeBySlug($lang, $slug);
                $this->routes->commit();
                $log->record(function () use ($routesSnapshot): void {
                    $this->routes->forceWrite($routesSnapshot);
                });
            }

            // Phase C: overlay write — last commit. Failure here
            // rolls back recycle + routes via the log.
            if ($overlay !== null) {
                $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);
            }

            return ['slug' => $slug];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    // ============================================================
    // SAVE (field values)
    // ============================================================

    /**
     * Persist edited field values for one tree node.
     *
     * @param array<string, mixed> $bucketed  bucketed values from PageMetaFormDecoder
     *        Shape:
     *          'overlay'     => string-keyed map of overlay-item-level fields
     *          'routes'      => ['slug' => string, 'url' => string] (only present for slug type)
     *          'frontmatter' => string-keyed map of frontmatter fields (dot paths)
     */
    public function save(
        string $lang,
        string $section,
        string $indexPath,
        array  $bucketed,
        string $expectedVersion,
    ): array {
        return $this->withLock(fn() => $this->saveImpl(
            $lang, $section, $indexPath, $bucketed, $expectedVersion,
        ));
    }

    private function saveImpl(
        string $lang,
        string $section,
        string $indexPath,
        array  $bucketed,
        string $expectedVersion,
    ): array {
        $this->assertSection($section);
        $this->verifyVersion($expectedVersion);

        $log = new TreeMutationLog();
        try {
            $resolvedSlug = null;

            // ----- Routes bucket -----
            // Slug rename / URL change live in RoutesBucketApplier.
            // Returns the resolved slug (post-rename if any) and the
            // urlWasChanged flag that drives the URL-cascade pass.
            $urlWasChanged = false;
            if (!empty($bucketed['routes']) && is_array($bucketed['routes'])) {
                $resolution = $this->routesBucket->apply(
                    $lang, $section, $indexPath, $bucketed['routes'], $log,
                );
                $resolvedSlug  = $resolution->resolvedSlug;
                $urlWasChanged = $resolution->urlWasChanged;

                // Cascade: if THIS item's URL changed, every slug-
                // descendant inherits a new prefix. The overlay-
                // bucket block below handles label/anchor changes
                // that also shift segments; URL-only changes need
                // their own cascade pass.
                if ($urlWasChanged && $section !== 'unsorted') {
                    $cascadeOverlay = $this->overlayWriter->read($lang);
                    $this->urls->cascade($lang, $cascadeOverlay, $section, $indexPath, $log);
                    $this->routes->commit();
                }
            }

            // ----- Frontmatter bucket -----
            // Re-load + mutate + save the .md file. Applies only when
            // we have a slug to address the file by.
            if (!empty($bucketed['frontmatter']) && is_array($bucketed['frontmatter']) && $resolvedSlug === null) {
                // Resolve slug if we haven't yet (no routes bucket
                // mutation in this save).
                $resolvedSlug = $this->resolveSlugAtPath($lang, $section, $indexPath);
            }
            if (!empty($bucketed['frontmatter']) && is_array($bucketed['frontmatter']) && $resolvedSlug !== null) {
                $this->frontmatterBucket->apply($lang, $resolvedSlug, $bucketed['frontmatter']);
                // No rollback hook — PageRepository::save took a
                // history snapshot of the pre-write state. Restore is
                // via the existing history-restore endpoint.
            }

            // ----- Overlay bucket -----
            if (!empty($bucketed['overlay']) && is_array($bucketed['overlay']) && $section !== 'unsorted') {
                $this->overlayBucket->apply(
                    $lang, $section, $indexPath, $bucketed['overlay'], $resolvedSlug, $log,
                );
            } elseif ($resolvedSlug !== null && $section !== 'unsorted') {
                // Slug rename happened but no overlay bucket — we still
                // need to rewrite the slug reference in the overlay.
                $this->overlayBucket->updateSlugRefOnly($lang, $section, $indexPath, $resolvedSlug);
            }

            // Final URL surfaced to the client + audit log. Re-read
            // from the (now committed) routes table rather than
            // echoing $newUrl, so we never report a URL that didn't
            // actually land in routes.php (e.g. when the URL was
            // ignored because it equalled the current one, or when a
            // cascade pass corrected it). Null when the entry has no
            // route (folder/anchor/href).
            $finalUrl = ($resolvedSlug !== null)
                ? $this->routes->urlForSlug($lang, $resolvedSlug)
                : null;
            return [
                'slug'      => $resolvedSlug,
                'indexPath' => $indexPath,
                'url'       => $finalUrl,
            ];
        } catch (\Throwable $e) {
            $log->rollback();
            throw $e;
        }
    }

    // ============================================================
    // Helpers — small internal utilities
    // ============================================================

    /**
     * Resolve the slug at a given tree position. Returns null when
     * the item is not slug-typed or the position is empty.
     *
     * Shared helper for the frontmatter-bucket path in saveImpl when
     * the routes bucket didn't run (and therefore didn't pre-compute
     * the resolved slug).
     */
    private function resolveSlugAtPath(string $lang, string $section, string $indexPath): ?string
    {
        if ($section === 'unsorted') {
            return $this->unsortedResolver->resolve($lang, $indexPath);
        }
        $overlay = $this->overlayWriter->read($lang);
        if (!is_array($overlay[$this->treeRoot][$section] ?? null)) {
            return null;
        }
        $item = Indexing::locateItem($overlay[$this->treeRoot][$section], $indexPath);
        return is_string($item['slug'] ?? null) ? $item['slug'] : null;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    private static function deriveItemType(array $item): string
    {
        if (isset($item['anchor'])) return 'anchor';
        if (isset($item['slug']))   return 'slug';
        if (isset($item['href']))   return 'href';
        return 'folder';
    }

    private function assertSection(string $section): void
    {
        if ($section === 'unsorted') return;
        if (preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $section) !== 1) {
            throw new \RuntimeException("Bad section key: '{$section}'.");
        }
        // Cross-check against the configured tree-section allowlist —
        // shape-validity alone wouldn't prevent persistence under a
        // novel section key. Listed sections come from
        // `config/i18n.php → i18n_overlay.page_tree.sections`; a theme
        // adding a new section adds it there, never here.
        if (!in_array($section, $this->configuredSections, true)) {
            throw new \RuntimeException(
                "Section '{$section}' is not in the configured tree sections ("
                . (empty($this->configuredSections)
                    ? '(none configured)'
                    : implode(', ', $this->configuredSections))
                . ').'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlankItem(string $type): array
    {
        $base = [
            'label'  => '[NEW_PAGE]',
            'hidden' => true,
        ];
        return match ($type) {
            'slug'   => $base, // slug filled in by caller after allocation
            'href'   => $base + ['href'   => '#'],
            'anchor' => $base + ['anchor' => 'anchor'],
            'folder' => $base + ['children' => []],
            default  => throw new \RuntimeException("Unknown type '{$type}'."),
        };
    }

    private function verifyVersion(string $expected): void
    {
        $current = $this->aggregator->build()->version;
        if (!hash_equals($current, $expected)) {
            throw new TreeVersionConflictException(
                "Tree has been modified since last read (expected version {$expected}, current {$current})."
            );
        }
    }
}
