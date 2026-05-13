<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Content\PageDocument;
use H42\WhimAdmin\Content\PageRepository;

/**
 * Apply the `frontmatter` bucket of a save payload to the .md file
 * of one slug-typed tree node.
 *
 * Bucket shape: flat map of dot-path → string value. Supported paths:
 *
 *     'layout'           → header['layout']
 *     'meta.title'       → header['meta']['title']
 *     'meta.description' → header['meta']['description']
 *     'disabled'         → header['disabled']
 *     'hidden'           → header['hidden']
 *
 * An empty-string or null value REMOVES the key from the header
 * (versus setting it to empty). Mirrors the AttributeParser-compatible
 * format the public-site loader consumes.
 *
 * Side-effects: loads the .md (creating a blank PageDocument if absent),
 * applies the bucket, writes via PageRepository::save (which takes its
 * own history snapshot of the pre-write state — restore via the
 * existing history-restore endpoint).
 *
 * No rollback hooks registered on the mutation log: PageRepository::save
 * is its own transactional unit (atomic rename + round-trip integrity
 * check + history snapshot). A subsequent failure in the same batch
 * cannot undo this write without an explicit revert — the operator
 * uses the history view if needed.
 *
 * Extracted from TreeMutator::saveImpl's frontmatter-bucket block.
 * Behaviour-identical.
 */
final class FrontmatterBucketApplier
{
    public function __construct(
        private PageRepository $pages,
    ) {
    }

    /**
     * @param array<string, mixed> $bucket  dot.path => string|''|null
     */
    public function apply(string $lang, string $resolvedSlug, array $bucket): void
    {
        $doc = $this->pages->exists($lang, $resolvedSlug)
            ? $this->pages->load($lang, $resolvedSlug)
            : new PageDocument(header: [], blocks: []);
        $this->applyBucket($doc, $bucket);
        $this->pages->save($lang, $resolvedSlug, $doc);
    }

    /**
     * @param array<string, mixed> $bucket
     */
    private function applyBucket(PageDocument $doc, array $bucket): void
    {
        foreach ($bucket as $dotPath => $value) {
            if (!is_string($dotPath)) continue;
            $parts = explode('.', $dotPath);
            if (count($parts) === 1) {
                $key = $parts[0];
                if ($value === '' || $value === null) {
                    unset($doc->header[$key]);
                } elseif (is_string($value)) {
                    $doc->header[$key] = $value;
                }
            } elseif (count($parts) === 2) {
                [$outer, $inner] = $parts;
                if ($value === '' || $value === null) {
                    if (isset($doc->header[$outer]) && is_array($doc->header[$outer])) {
                        unset($doc->header[$outer][$inner]);
                        if ($doc->header[$outer] === []) {
                            unset($doc->header[$outer]);
                        }
                    }
                } elseif (is_string($value)) {
                    if (!isset($doc->header[$outer]) || !is_array($doc->header[$outer])) {
                        $doc->header[$outer] = [];
                    }
                    $doc->header[$outer][$inner] = $value;
                }
            }
            // Deeper dot-paths not supported; PageLoader's header
            // allowlist is two-level (meta.title, meta.description).
        }
    }
}
