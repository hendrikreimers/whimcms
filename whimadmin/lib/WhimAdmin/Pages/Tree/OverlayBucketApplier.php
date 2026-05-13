<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Pages\OverlayWriter;
use H42\WhimAdmin\Pages\RoutesUpdater;
use H42\WhimCMS\Content\HrefSanitizer;

/**
 * Apply the `overlay` bucket of a save payload to one tree node.
 *
 * Bucket shape (sub-set of these keys may be present):
 *
 *     [
 *       'label'   => 'Home',         // string  — overwrites item label
 *       'hidden'  => true|'true',    // bool    — bool-coerced
 *       'href'    => 'https://...',  // string  — re-validated via allowlist
 *       'anchor'  => 'top',          // string  — anchor-id pattern
 *       'slug'    => '...',          // IGNORED here; slug lives in routes bucket
 *     ]
 *
 * `apply()` is responsible for:
 *
 *   1. Reading the overlay and locating the target item.
 *   2. Snapshotting the segment-relevant fields (label/anchor/href)
 *      BEFORE applying the bucket so we can detect a change after.
 *   3. Applying the bucket (with per-field re-validation — defense in
 *      depth over the decoder's own checks).
 *   4. Updating the overlay's slug reference if the caller's routes-
 *      bucket renamed the slug in this same save.
 *   5. Triggering the URL cascade over slug-descendants when a
 *      segment-relevant field changed (label/anchor/href influence the
 *      parent-segment derivation in `UrlDeriver`).
 *   6. Writing the overlay back atomically.
 *
 * Throws TreeInternalException when the section is missing in the
 * overlay (tree changed since the optimistic-locking version was
 * computed) and RuntimeException when a value fails per-field
 * validation. Per-key validation here mirrors OverlayWriter's
 * validateItem; running it twice gives the editor a clear error
 * message before the writer's strict gate fires.
 *
 * Skipped entirely for `section === 'unsorted'` — unsorted entries
 * have no overlay item to apply overlay-bucket fields to. The caller
 * pre-checks the section and only invokes apply() when applicable.
 *
 * Extracted from TreeMutator::saveImpl's overlay-bucket block plus
 * applyOverlayBucket + toBool helpers. Behaviour-identical.
 */
final class OverlayBucketApplier
{
    /**
     * @param list<string> $allowedOverlaySections  i18n_overlay.allowed_sections
     */
    public function __construct(
        private OverlayWriter $overlayWriter,
        private RoutesUpdater $routes,
        private UrlDeriver $urls,
        private string $treeRoot,
        private array $allowedOverlaySections,
    ) {
    }

    /**
     * @param array<string, mixed> $bucket  overlay-bucket from PageMetaFormDecoder
     */
    public function apply(
        string $lang,
        string $section,
        string $indexPath,
        array $bucket,
        ?string $resolvedSlug,
        TreeMutationLog $log,
    ): void {
        $overlay = $this->overlayWriter->read($lang);
        if (!is_array($overlay[$this->treeRoot][$section] ?? null)) {
            throw new TreeInternalException(
                'The page tree changed since you last viewed it — please reload and retry.',
                "section '{$section}' missing in overlay '{$this->treeRoot}'",
            );
        }
        $item = Indexing::locateItem($overlay[$this->treeRoot][$section], $indexPath);

        // Snapshot the parent-segment-relevant fields BEFORE applying
        // the bucket so we know whether to cascade.
        $oldLabel  = is_string($item['label']  ?? null) ? $item['label']  : '';
        $oldAnchor = is_string($item['anchor'] ?? null) ? $item['anchor'] : null;
        $oldHref   = is_string($item['href']   ?? null) ? $item['href']   : null;

        $this->applyBucketToItem($item, $bucket);

        // If slug was renamed in this same save, update the overlay
        // slug reference too.
        if ($resolvedSlug !== null && isset($item['slug']) && $item['slug'] !== $resolvedSlug) {
            $item['slug'] = $resolvedSlug;
        }

        Indexing::replaceItem($overlay[$this->treeRoot][$section], $indexPath, $item);

        // Cascade children if any segment-relevant field changed.
        // URL changes on slug-typed items are also a cascade trigger
        // but they're handled in the routes-bucket above; this
        // branch only catches label / anchor / href-label changes
        // that propagate via kebab() or anchor-id derivation.
        $newLabel  = is_string($item['label']  ?? null) ? $item['label']  : '';
        $newAnchor = is_string($item['anchor'] ?? null) ? $item['anchor'] : null;
        $newHref   = is_string($item['href']   ?? null) ? $item['href']   : null;
        if ($oldLabel !== $newLabel || $oldAnchor !== $newAnchor || $oldHref !== $newHref) {
            $this->urls->cascade($lang, $overlay, $section, $indexPath, $log);
            // Mandatory commit — cascade mutates routes in-memory
            // only. Without this, label-rename cascades silently
            // never reach routes.php.
            $this->routes->commit();
        }
        $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);
    }

    /**
     * Update only the slug reference in the overlay (without applying
     * any overlay bucket). Used when a slug rename happened in the
     * routes bucket but no overlay-bucket changes were submitted in
     * the same save — the overlay's old slug-ref would otherwise
     * dangle.
     */
    public function updateSlugRefOnly(string $lang, string $section, string $indexPath, string $resolvedSlug): void
    {
        $overlay = $this->overlayWriter->read($lang);
        if (!is_array($overlay[$this->treeRoot][$section] ?? null)) {
            return;
        }
        $item = Indexing::locateItem($overlay[$this->treeRoot][$section], $indexPath);
        if (isset($item['slug']) && $item['slug'] !== $resolvedSlug) {
            $item['slug'] = $resolvedSlug;
            Indexing::replaceItem($overlay[$this->treeRoot][$section], $indexPath, $item);
            $this->overlayWriter->write($lang, $overlay, $this->treeRoot, $this->allowedOverlaySections);
        }
    }

    /**
     * @param array<string, mixed> &$item
     * @param array<string, mixed> $bucket
     */
    private function applyBucketToItem(array &$item, array $bucket): void
    {
        foreach ($bucket as $key => $value) {
            if (!is_string($key)) continue;
            switch ($key) {
                case 'label':
                    if (!is_string($value)) break;
                    $item['label'] = $value;
                    break;
                case 'hidden':
                    $item['hidden'] = self::toBool($value);
                    break;
                case 'slug':
                    // Slug is handled via routes bucket. Ignore.
                    break;
                case 'href':
                    if (!is_string($value)) break;
                    // Overlay hrefs may use path markers (`~/`, `^/`)
                    // — I18n::load resolves them at render time.
                    // Validate by stripping the marker and re-running
                    // the allowlist against the resolved `/...` form.
                    $probe = (str_starts_with($value, '~/') || str_starts_with($value, '^/'))
                        ? substr($value, 1) : $value;
                    if ($probe === '' || HrefSanitizer::check($probe) === null) {
                        throw new \RuntimeException("href rejected by URL allowlist.");
                    }
                    $item['href'] = $value;
                    break;
                case 'anchor':
                    if (!is_string($value)) break;
                    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) !== 1) {
                        throw new \RuntimeException("anchor must match [A-Za-z][A-Za-z0-9_-]+.");
                    }
                    $item['anchor'] = $value;
                    break;
                default:
                    // Silently drop unknown keys — the OverlayWriter
                    // would reject them at commit anyway.
                    break;
            }
        }
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (!is_string($v)) return false;
        return in_array(strtolower(trim($v)), ['true', 'yes', '1'], true);
    }
}
