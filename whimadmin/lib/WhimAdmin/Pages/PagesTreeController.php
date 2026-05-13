<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Content\PageRepository;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\Pages\Tree\LanguageTree;
use H42\WhimAdmin\Pages\Tree\TreeAggregator;
use H42\WhimAdmin\Pages\Tree\TreeNode;
use H42\WhimAdmin\Pages\Tree\TreeView;
use H42\WhimAdmin\View\Renderer;
use H42\WhimCMS\Config as CoreConfig;
use H42\WhimCMS\Content\ContentNotFoundException;
use H42\WhimCMS\Content\Identifiers;
use H42\WhimCMS\Content\ParseException;

/**
 * Read-only JSON endpoints for the split-view page editor.
 *
 *   GET /pages/tree            → full TreeView snapshot (all languages,
 *                                all configured sections, unsorted bucket,
 *                                tree-version handle for optimistic
 *                                locking in Phase 2)
 *   GET /pages/tree/types      → catalogue of available page-types and
 *                                their field schemas (consumed by the
 *                                right-pane editor in Phase 3)
 *
 * No mutations live here — those land in Phase 2's TreeMutationController.
 * Keeping read and write controllers separate keeps the audit surface
 * tight: a GET endpoint that ever grows a write side-effect is a
 * red-flag review.
 *
 * Authentication is enforced by the kernel's authGuard before this
 * controller is reached. CSRF tokens are not required for safe GET
 * requests; the response is JSON with `Cache-Control: no-store` and
 * `Content-Type: application/json; charset=utf-8`.
 */
final class PagesTreeController
{
    public function __construct(
        private TreeAggregator       $aggregator,
        private PageTypeSchemaLoader $pageTypes,
        private PageRepository       $pages,
        private Csrf                 $csrf,
        private AuditLog             $audit,
        private Renderer             $views,
        private string               $username,
    ) {
    }

    /**
     * Render the split-view editor shell. The page is mostly empty
     * HTML + `<template>` blocks the JS clones at runtime. All data
     * arrives via the JSON endpoints (`/pages/tree`, `/pages/tree/types`)
     * after the page has loaded — there is no server-rendered tree
     * markup, which keeps this endpoint cheap (single template render,
     * no aggregator walk) and the JSON contract single-source.
     */
    public function index(Request $req): Response
    {
        return Response::html($this->views->page('pages-tree/index', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'SITE_ROOT'   => $req->siteRoot(),
            'MAIN_CLASS'  => 'is-fullwidth',
        ]));
    }

    public function tree(Request $req): Response
    {
        $view = $this->aggregator->build();
        $payload = $this->serialiseView($view);
        // Embed a CSRF token for the mutation endpoints so the UI
        // doesn't need a separate fetch. The token is scoped to
        // `whimadmin:tree` and bound to client IP+UA; emitting it in
        // an authed JSON response is the same trust posture as
        // rendering it into a server-side form.
        $payload['csrfToken'] = $this->csrf->issue('tree');
        return Response::json($payload);
    }

    public function types(Request $req): Response
    {
        $types = $this->pageTypes->all();
        $out = [];
        foreach ($types as $id => $t) {
            $out[$id] = $this->serialiseType($t);
        }
        // Allowed layouts piggy-back on the types endpoint so the
        // editor needs one boot fetch for static metadata instead of
        // a separate /pages/tree/layouts call. The list is operator-
        // managed in core config/content.php → allowed_layouts.
        $layouts = (array)CoreConfig::get('content.allowed_layouts', ['default']);
        $layouts = array_values(array_filter($layouts, 'is_string'));
        if ($layouts === []) $layouts = ['default'];
        return Response::json([
            'types'   => $out,
            'layouts' => $layouts,
        ]);
    }

    /**
     * Per-node detail endpoint.
     *
     * The tree JSON omits front-matter values (cost: would require
     * parsing every .md per /pages/tree fetch). The editor calls this
     * endpoint when the user selects a slug-typed node so the
     * meta-title / meta-description / layout / disabled fields render
     * with their actual current values rather than empty.
     *
     * For non-slug nodes this returns the same shape the tree
     * already provides — no .md to read — so the client can call it
     * uniformly without branching.
     *
     *   GET /pages/tree/node?lang=&section=&indexPath=
     *
     * Returns:
     *   { node: { type, label, slug?, url?, hidden, disabled?, hasMd?, frontmatter?: {...} } }
     *
     * `frontmatter` is only present for slug-typed entries whose .md
     * exists. Missing .md is signalled by `hasMd: false`.
     */
    public function node(Request $req): Response
    {
        $lang      = (string)$req->query('lang', '');
        $section   = (string)$req->query('section', '');
        $indexPath = (string)$req->query('indexPath', '');
        if (!Identifiers::isValidLang($lang)) {
            return Response::json(['error' => 'validation', 'message' => 'Bad lang.'], 400);
        }
        if ($section !== 'unsorted' && preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $section) !== 1) {
            return Response::json(['error' => 'validation', 'message' => 'Bad section.'], 400);
        }
        if (preg_match('/^\d+(\/\d+)*$/', $indexPath) !== 1) {
            return Response::json(['error' => 'validation', 'message' => 'Bad indexPath.'], 400);
        }

        $view = $this->aggregator->build();
        $node = $this->locateInView($view, $lang, $section, $indexPath);
        if ($node === null) {
            return Response::json(['error' => 'not-found', 'message' => 'Node not found.'], 404);
        }

        $out = [
            'type'      => $node->type,
            'label'     => $node->label,
            'hidden'    => $node->hidden,
            'indexPath' => $node->indexPath,
            'warnings'  => $node->warnings,
        ];
        if ($node->slug !== null)   $out['slug']     = $node->slug;
        if ($node->url !== null)    $out['url']      = $node->url;
        if ($node->href !== null)   $out['href']     = $node->href;
        if ($node->anchor !== null) $out['anchor']   = $node->anchor;
        if ($node->type === 'slug') {
            $out['hasMd']    = $node->hasMd;
            $out['disabled'] = $node->disabled;
            // Frontmatter probe — only on slug + only when .md exists.
            if ($node->hasMd && $node->slug !== null) {
                try {
                    $doc = $this->pages->load($lang, $node->slug);
                    $fm = $doc->header;
                    $meta = is_array($fm['meta'] ?? null) ? $fm['meta'] : [];
                    $out['frontmatter'] = [
                        'layout'           => is_string($fm['layout']   ?? null) ? $fm['layout']           : '',
                        'meta_title'       => is_string($meta['title']  ?? null) ? $meta['title']          : '',
                        'meta_description' => is_string($meta['description'] ?? null) ? $meta['description'] : '',
                        'disabled'         => is_string($fm['disabled'] ?? null) ? $fm['disabled']         : '',
                        'hidden'           => is_string($fm['hidden']   ?? null) ? $fm['hidden']           : '',
                    ];
                } catch (ContentNotFoundException) {
                    $out['hasMd'] = false;
                } catch (ParseException $e) {
                    $out['frontmatter_error'] = $e->getMessage();
                }
            }
        }
        return Response::json(['node' => $out]);
    }

    /** Walk the prebuilt view to locate one node. */
    private function locateInView(TreeView $view, string $lang, string $section, string $indexPath): ?TreeNode
    {
        foreach ($view->languages as $lt) {
            if ($lt->lang !== $lang) continue;
            foreach ($lt->sections as $s) {
                if ($s->key !== $section) continue;
                $parts = explode('/', $indexPath);
                $cur = $s->items;
                $node = null;
                foreach ($parts as $idx) {
                    $i = (int)$idx;
                    if (!isset($cur[$i])) return null;
                    $node = $cur[$i];
                    $cur = $node->children;
                }
                return $node;
            }
        }
        return null;
    }

    // ============================================================
    // Serialisation — explicit field whitelisting; no `null`s,
    // no object-to-array drift. The JSON shape is the API contract.
    // ============================================================

    /**
     * @return array<string, mixed>
     */
    private function serialiseView(TreeView $view): array
    {
        $languages = [];
        foreach ($view->languages as $lt) {
            $languages[] = $this->serialiseLanguage($lt);
        }
        return [
            'root'      => $view->root,
            'version'   => $view->version,
            'languages' => $languages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseLanguage(LanguageTree $lt): array
    {
        $sections = [];
        foreach ($lt->sections as $s) {
            $sections[] = [
                'key'        => $s->key,
                'label'      => $s->label,
                'isUnsorted' => $s->isUnsorted,
                'items'      => array_map(fn(TreeNode $n) => $this->serialiseNode($n), $s->items),
            ];
        }
        return [
            'lang'      => $lt->lang,
            'isDefault' => $lt->isDefault,
            'sections'  => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseNode(TreeNode $n): array
    {
        $base = [
            'type'      => $n->type,
            'label'     => $n->label,
            'hidden'    => $n->hidden,
            'indexPath' => $n->indexPath,
            'children'  => array_map(fn(TreeNode $c) => $this->serialiseNode($c), $n->children),
            'warnings'  => $n->warnings,
        ];
        return match ($n->type) {
            'slug' => $base + [
                'slug'     => $n->slug,
                'url'      => $n->url,
                'hasMd'    => $n->hasMd,
                'disabled' => $n->disabled,
            ],
            'href'   => $base + ['href'   => $n->href],
            'anchor' => $base + ['anchor' => $n->anchor],
            default  => $base, // folder
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseType(PageType $t): array
    {
        $fields = [];
        foreach ($t->fields as $name => $f) {
            $entry = [
                'name'     => $f->name,
                'type'     => $f->type,
                'target'   => $f->target,
                'label'    => $f->label,
                'required' => $f->required,
            ];
            if ($f->extra !== []) {
                $entry['extra'] = $f->extra;
            }
            $fields[$name] = $entry;
        }
        return [
            'id'             => $t->id,
            'label'          => $t->label,
            'description'   => $t->description,
            'fields'         => $fields,
            'required'       => $t->required,
            'requiresMd'     => $t->requiresMd,
            'requiresRoute' => $t->requiresRoute,
        ];
    }
}
