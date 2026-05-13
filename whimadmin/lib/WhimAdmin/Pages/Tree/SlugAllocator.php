<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Content\PageRepository;
use H42\WhimAdmin\Pages\RoutesUpdater;
use H42\WhimCMS\Content\Identifiers;

/**
 * Generate a random unused slug for new pages.
 *
 * The candidate is `page-<12-hex-chars>` — ~2^48 collision space, plus
 * a bounded retry loop guards against the freak case of a clash with
 * an already-routed slug or an already-on-disk `.md`. After
 * SLUG_RETRIES failures we throw — that's effectively a routes.php
 * corruption signal, not a collision the operator can resolve by
 * clicking again.
 *
 * Extracted from TreeMutator::allocateRandomSlug. Behaviour-identical.
 */
final class SlugAllocator
{
    /** Random-slug allocation retries before giving up. */
    public const SLUG_RETRIES = 50;

    public function __construct(
        private RoutesUpdater $routes,
        private PageRepository $pages,
    ) {
    }

    public function allocate(string $lang): string
    {
        for ($i = 0; $i < self::SLUG_RETRIES; $i++) {
            $candidate = 'page-' . bin2hex(random_bytes(6));
            if (!Identifiers::isValidSlug($candidate)) continue;
            if ($this->routes->slugExists($lang, $candidate)) continue;
            if ($this->pages->exists($lang, $candidate)) continue;
            return $candidate;
        }
        throw new \RuntimeException("Cannot allocate a unique random slug after " . self::SLUG_RETRIES . " attempts.");
    }
}
