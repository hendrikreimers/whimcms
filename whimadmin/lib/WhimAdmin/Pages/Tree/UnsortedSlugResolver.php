<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Resolve a slug by its synthetic position in the Unsorted bucket of
 * one language.
 *
 * The Unsorted bucket is a derived view (routes-without-overlay-ref);
 * we rebuild it on demand by asking the aggregator for the current
 * tree and walking to the unsorted section of the requested language.
 *
 * Extracted from TreeMutator::resolveUnsortedSlug. Behaviour-identical.
 */
final class UnsortedSlugResolver
{
    public function __construct(
        private TreeAggregator $aggregator,
    ) {
    }

    /**
     * Return the slug at $indexPath inside the unsorted bucket of
     * $lang, or null if no entry sits at that position.
     *
     * indexPath for unsorted MUST be a single integer (no nesting —
     * unsorted is flat). Throws on shape violation.
     */
    public function resolve(string $lang, string $indexPath): ?string
    {
        if (preg_match('/^\d+$/', $indexPath) !== 1) {
            throw new \RuntimeException("Unsorted indexPath must be a single integer.");
        }
        $view = $this->aggregator->build();
        foreach ($view->languages as $lt) {
            if ($lt->lang !== $lang) continue;
            foreach ($lt->sections as $s) {
                if (!$s->isUnsorted) continue;
                $idx = (int)$indexPath;
                if (!isset($s->items[$idx])) return null;
                return $s->items[$idx]->slug;
            }
        }
        return null;
    }
}
