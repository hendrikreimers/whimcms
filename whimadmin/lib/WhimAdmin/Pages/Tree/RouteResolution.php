<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Outcome of RoutesBucketApplier::apply.
 *
 *   resolvedSlug    The slug the rest of the save batch should
 *                   operate on (post-rename if a rename happened,
 *                   else the current slug). Null is impossible —
 *                   the applier throws TreeInternalException when
 *                   it cannot resolve the slug.
 *
 *   urlWasChanged   Whether a routes.<lang> URL value was rewritten
 *                   for this slug. Drives the URL-cascade pass over
 *                   slug-descendants in the caller. Independent of
 *                   the slug-rename flag: a save can rename a slug
 *                   without touching its URL (e.g. when the URL is
 *                   a custom path that doesn't track the slug).
 *
 * Pure value object — readonly properties, no methods. Lives in
 * its own file so the type referenced by the applier's signature
 * is loadable via PSR-4 without circular includes.
 */
final class RouteResolution
{
    public function __construct(
        public readonly string $resolvedSlug,
        public readonly bool $urlWasChanged,
    ) {
    }
}
