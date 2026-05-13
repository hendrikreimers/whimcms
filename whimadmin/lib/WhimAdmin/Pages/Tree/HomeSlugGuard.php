<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

use H42\WhimAdmin\Pages\OverlayWriter;
use H42\WhimAdmin\Pages\RoutesUpdater;

/**
 * Block delete- and retype-away-from-slug on the home page.
 *
 * Home = the slug mapped by `routes.<lang>['']`. If the editor were
 * allowed to recycle it or change it into a non-slug type, the empty-
 * URL key in routes.php would lose its target — the public site's
 * root would 404 and `nav-core.html`'s brand link
 * (`{% safe_href: URLS.home %}`) would render an empty href.
 *
 * The operator can re-route URL `''` to a different slug by opening
 * any other routed page in the tree editor, clearing its URL field to
 * `''`, and saving — that page then becomes the home target. After
 * that, the previous home page is deletable like any other.
 * Deliberate two-step, not an editor accident.
 *
 * Extracted from TreeMutator::refuseIfHomeSlug. Behaviour-identical.
 */
final class HomeSlugGuard
{
    public function __construct(
        private RoutesUpdater $routes,
        private OverlayWriter $overlayWriter,
        private UnsortedSlugResolver $unsorted,
        private string $treeRoot,
    ) {
    }

    public function refuse(string $lang, string $section, string $indexPath): void
    {
        $homeSlug = $this->routes->read($lang)[''] ?? null;
        if (!is_string($homeSlug) || $homeSlug === '') return;

        $targetSlug = null;
        if ($section === 'unsorted') {
            $targetSlug = $this->unsorted->resolve($lang, $indexPath);
        } else {
            $overlay = $this->overlayWriter->read($lang);
            if (is_array($overlay[$this->treeRoot][$section] ?? null)) {
                try {
                    $item = Indexing::locateItem($overlay[$this->treeRoot][$section], $indexPath);
                    $targetSlug = is_string($item['slug'] ?? null) ? $item['slug'] : null;
                } catch (\Throwable) {
                    return; // locate failure — let the main flow handle the error
                }
            }
        }
        if ($targetSlug === $homeSlug) {
            throw new \RuntimeException(
                "Cannot remove the home page ('{$homeSlug}'). "
                . "Open another routed page first, change its URL field to '' (empty), and save — "
                . "that page then becomes the home target. After that, this page is deletable like any other.",
            );
        }
    }
}
