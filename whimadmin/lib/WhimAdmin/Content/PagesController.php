<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Content;

use H42\WhimAdmin\Assets\AssetBrowser;
use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\View\Renderer;
use H42\WhimCMS\Config as CoreConfig;
use H42\WhimCMS\Content\ContentNotFoundException;
use H42\WhimCMS\Content\Identifiers;
use H42\WhimCMS\Content\ParseException;

/**
 * Page-management controller (Phase 3 + 4).
 *
 *   GET  /pages                          listing
 *   GET  /pages/edit?lang&slug           edit form
 *   POST /pages/edit?lang&slug           edit form submit (action-dispatched)
 *   GET  /pages/new                      new-page form
 *   POST /pages/new                      create page
 *
 * Edit-form submit actions (POST `action` field):
 *   save                — write current state
 *   add-block           — append new block of `_add_block_type`
 *   remove:<N>          — drop block at index N
 *   move-up:<N>         — swap block N with N-1
 *   move-down:<N>       — swap block N with N+1
 *   cut:<N>             — move block N to clipboard
 *   paste-after:<N>     — insert clipboard contents at N+1; clears clipboard
 *   delete-page         — recycle the entire page (redirect to /pages)
 */
final class PagesController
{
    private const FORM_ID                = 'page-save';
    private const FORM_ID_NEW            = 'page-new';
    private const FORM_ID_RECYCLER       = 'page-recycler';
    private const FORM_ID_HISTORY        = 'page-history';
    private const ACTION_PATTERN         = '/^([a-z][a-z-]{0,30})(?::(-?[0-9]+|[a-z][a-z0-9-]*))?$/';
    private const HISTORY_FILENAME_REGEX = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}(?:_[0-9]+)?\.md$/';

    // Action vocabulary for the edit form. Anything not in this set is
    // a "Unknown action" error — keeps typos in the switch obvious and
    // gives the audit log a stable list to grep against.
    private const ACTION_SAVE        = 'save';
    private const ACTION_ADD_BLOCK   = 'add-block';
    private const ACTION_REMOVE      = 'remove';
    private const ACTION_MOVE_UP     = 'move-up';
    private const ACTION_MOVE_DOWN   = 'move-down';
    private const ACTION_CUT         = 'cut';
    private const ACTION_PASTE_AFTER = 'paste-after';
    private const ACTION_DELETE_PAGE = 'delete-page';

    public function __construct(
        private PageRepository $pages,
        private BlockSchemaLoader $schemas,
        private FormRenderer $formRenderer,
        private FormDecoder $formDecoder,
        private ClipboardStore $clipboard,
        private AssetBrowser $assetBrowser,
        private Recycler $recycler,
        private HistoryStore $history,
        private Csrf $csrf,
        private Renderer $views,
        private AuditLog $audit,
        private string $username,
    ) {
    }

    // ============================================================
    // Listing
    // ============================================================

    public function list(Request $req): Response
    {
        $rows = $this->pages->listAll();
        // Group by language. listAll() already sorts by [lang, slug] so
        // a single linear pass suffices.
        $grouped = [];
        foreach ($rows as $r) {
            $lang = $r['lang'];
            $grouped[$lang] ??= ['lang' => $lang, 'pages' => []];
            $grouped[$lang]['pages'][] = [
                'slug'        => $r['slug'],
                'mtime_human' => $r['mtime'] === 0 ? '' : gmdate('Y-m-d H:i', $r['mtime']),
            ];
        }
        $groups = array_values($grouped);
        $notice = $req->query('created') === '1' ? 'Page created.' :
                  ($req->query('deleted') === '1' ? 'Page moved to recycler.' : '');
        return Response::html($this->views->page('pages/list', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'GROUPS'      => $groups,
            'EMPTY'       => $groups === [] ? 'yes' : '',
            'NOTICE'      => $notice,
        ]));
    }

    // ============================================================
    // Edit
    // ============================================================

    public function edit(Request $req): Response
    {
        [$lang, $slug] = $this->extractLangSlug($req);
        if ($lang === null) {
            return Response::redirect($req->url('pages'));
        }
        try {
            $page = $this->pages->load($lang, $slug);
        } catch (ContentNotFoundException $e) {
            return Response::plain('Page not found.', 404);
        } catch (ParseException $e) {
            return $this->renderEditError($req, $lang, $slug, 'Parse error: ' . $e->getMessage());
        }
        return $this->renderEdit($req, $lang, $slug, $page, '', '');
    }

    public function save(Request $req): Response
    {
        [$lang, $slug] = $this->extractLangSlug($req);
        if ($lang === null) {
            return Response::redirect($req->url('pages'));
        }
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            $this->audit->record('page.csrf.invalid', $req->clientIp(), $this->username, ['lang' => $lang, 'slug' => $slug]);
            return $this->renderEditError($req, $lang, $slug, 'Form expired. Please reload and resubmit.');
        }

        $rawAction = (string)$req->post('action', self::ACTION_SAVE);
        if (preg_match(self::ACTION_PATTERN, $rawAction, $m) !== 1) {
            return $this->renderEditError($req, $lang, $slug, 'Bad action.');
        }
        $action = $m[1];
        $arg    = $m[2] ?? '';

        try {
            $doc = $this->formDecoder->decode($req->postAll());
        } catch (\Throwable $e) {
            return $this->renderEditError($req, $lang, $slug, 'Decode failed: ' . $e->getMessage());
        }

        $error = null;
        switch ($action) {
            case self::ACTION_SAVE:                                              break;
            case self::ACTION_ADD_BLOCK:   $error = $this->actAddBlock($doc, (string)$req->post('_add_block_type', '')); break;
            case self::ACTION_REMOVE:      $error = $this->actRemove($doc,    (int)$arg);     break;
            case self::ACTION_MOVE_UP:     $error = $this->actMoveBy($doc,    (int)$arg, -1); break;
            case self::ACTION_MOVE_DOWN:   $error = $this->actMoveBy($doc,    (int)$arg, +1); break;
            case self::ACTION_CUT:         $error = $this->actCut($doc,       (int)$arg);     break;
            case self::ACTION_PASTE_AFTER: $error = $this->actPasteAfter($doc, (int)$arg);    break;
            case self::ACTION_DELETE_PAGE: return $this->actDeletePage($req, $lang, $slug);
            default:                       $error = "Unknown action: {$action}";
        }
        if ($error !== null) {
            return $this->renderEditError($req, $lang, $slug, $error, $doc);
        }

        try {
            $this->pages->save($lang, $slug, $doc);
        } catch (\Throwable $e) {
            $this->audit->record('page.save.fail', $req->clientIp(), $this->username, [
                'lang' => $lang, 'slug' => $slug, 'error' => $e->getMessage(),
            ]);
            return $this->renderEditError($req, $lang, $slug, 'Save failed: ' . $e->getMessage(), $doc);
        }
        $this->audit->record('page.save.ok', $req->clientIp(), $this->username, [
            'lang' => $lang, 'slug' => $slug, 'action' => $action,
        ]);

        $url = $req->url('pages/edit') . '?lang=' . urlencode($lang) . '&slug=' . urlencode($slug);
        if ($action === self::ACTION_SAVE) $url .= '&saved=1';
        return Response::redirect($url);
    }

    // ============================================================
    // Create
    // ============================================================

    public function newForm(Request $req): Response
    {
        return Response::html($this->views->page('pages/new', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_NEW),
            'LAYOUTS'     => $this->layoutOptions('default'),
            'LANGS'       => $this->knownLangs(),
            'ERROR'       => '',
            'LANG'        => '',
            'SLUG'        => '',
            'TITLE'       => '',
        ]));
    }

    public function create(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID_NEW)) {
            return $this->renderNewError($req, '', '', '', 'Form expired.');
        }
        $lang   = trim((string)$req->post('lang', ''));
        $slug   = trim((string)$req->post('slug', ''));
        $title  = trim((string)$req->post('title', ''));
        $layout = trim((string)$req->post('layout', 'default'));

        if (!Identifiers::isValidLang($lang)) {
            return $this->renderNewError($req, $lang, $slug, $title, 'Invalid language code.');
        }
        if (!Identifiers::isValidSlug($slug)) {
            return $this->renderNewError($req, $lang, $slug, $title, 'Invalid slug.');
        }
        if ($this->pages->exists($lang, $slug)) {
            return $this->renderNewError($req, $lang, $slug, $title, 'A page with that lang/slug already exists.');
        }
        $allowedLayouts = (array)CoreConfig::get('content.allowed_layouts', ['default']);
        if (!in_array($layout, $allowedLayouts, true)) {
            return $this->renderNewError($req, $lang, $slug, $title, 'Layout not in allowlist.');
        }

        $header = ['layout' => $layout];
        if ($title !== '') {
            $header['meta'] = ['title' => $title];
        }
        $doc = new PageDocument(header: $header, blocks: []);
        try {
            $this->pages->save($lang, $slug, $doc);
        } catch (\Throwable $e) {
            return $this->renderNewError($req, $lang, $slug, $title, 'Create failed: ' . $e->getMessage());
        }
        $this->audit->record('page.create', $req->clientIp(), $this->username, ['lang' => $lang, 'slug' => $slug]);
        return Response::redirect(
            $req->url('pages/edit') . '?lang=' . urlencode($lang) . '&slug=' . urlencode($slug) . '&created=1'
        );
    }

    // ============================================================
    // Actions
    // ============================================================

    private function actAddBlock(PageDocument $doc, string $type): ?string
    {
        if (!Identifiers::isValidBlockType($type)) {
            return 'Bad block type.';
        }
        if ($this->schemas->get($type) === null) {
            return "Block type '{$type}' has no partial.";
        }
        $doc->blocks[] = new Block(type: $type, attrs: [], body: null);
        return null;
    }

    private function actRemove(PageDocument $doc, int $index): ?string
    {
        if (!isset($doc->blocks[$index])) return 'Block index out of range.';
        array_splice($doc->blocks, $index, 1);
        return null;
    }

    private function actMoveBy(PageDocument $doc, int $index, int $delta): ?string
    {
        if (!isset($doc->blocks[$index])) return 'Block index out of range.';
        $target = $index + $delta;
        if (!isset($doc->blocks[$target])) return null;
        $tmp = $doc->blocks[$index];
        $doc->blocks[$index]  = $doc->blocks[$target];
        $doc->blocks[$target] = $tmp;
        return null;
    }

    private function actCut(PageDocument $doc, int $index): ?string
    {
        if (!isset($doc->blocks[$index])) return 'Block index out of range.';
        $this->clipboard->set($this->username, $doc->blocks[$index]->cloneDeep());
        array_splice($doc->blocks, $index, 1);
        return null;
    }

    private function actPasteAfter(PageDocument $doc, int $index): ?string
    {
        $clip = $this->clipboard->get($this->username);
        if ($clip === null) return 'Clipboard is empty.';
        if ($index < -1 || $index >= count($doc->blocks)) return 'Paste position out of range.';
        array_splice($doc->blocks, $index + 1, 0, [$clip->cloneDeep()]);
        $this->clipboard->clear($this->username);
        return null;
    }

    private function actDeletePage(Request $req, string $lang, string $slug): Response
    {
        try {
            $this->pages->delete($lang, $slug);
        } catch (\Throwable $e) {
            return $this->renderEditError($req, $lang, $slug, 'Delete failed: ' . $e->getMessage());
        }
        $this->audit->record('page.delete', $req->clientIp(), $this->username, ['lang' => $lang, 'slug' => $slug]);
        return Response::redirect($req->url('pages') . '?deleted=1');
    }

    // ============================================================
    // Recycler
    // ============================================================

    public function recyclerView(Request $req): Response
    {
        $entries = $this->recycler->list();
        // Each entry's deletedAt is "Y-m-d_His" — humanise for display.
        $rows = [];
        foreach ($entries as $e) {
            $human = '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})$/', $e['deletedAt'], $m) === 1) {
                $human = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
            }
            $rows[] = $e + ['deleted_human' => $human];
        }
        $notice = $req->query('restored') === '1' ? 'Page restored.' :
                  ($req->query('purged')   === '1' ? 'Recycler emptied.' : '');
        return Response::html($this->views->page('pages/recycler', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_RECYCLER),
            'ENTRIES'     => $rows,
            'EMPTY'       => $rows === [] ? 'yes' : '',
            'NOTICE'      => $notice,
            'ERROR'       => '',
        ]));
    }

    public function recyclerRestore(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID_RECYCLER)) {
            return $this->renderRecyclerError($req, 'Form expired.');
        }
        $filename = (string)$req->post('file', '');
        try {
            $info = $this->recycler->restore($filename);
        } catch (\Throwable $e) {
            $this->audit->record('page.recycler.restore.fail', $req->clientIp(), $this->username, ['file' => $filename, 'error' => $e->getMessage()]);
            return $this->renderRecyclerError($req, $e->getMessage());
        }
        $this->audit->record('page.recycler.restore', $req->clientIp(), $this->username, $info);
        return Response::redirect($req->url('pages/recycler') . '?restored=1');
    }

    public function recyclerPurge(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID_RECYCLER)) {
            return $this->renderRecyclerError($req, 'Form expired.');
        }
        $count = $this->recycler->purgeAll();
        $this->audit->record('page.recycler.purge', $req->clientIp(), $this->username, ['count' => $count]);
        return Response::redirect($req->url('pages/recycler') . '?purged=1');
    }

    // ============================================================
    // History
    // ============================================================

    public function historyView(Request $req): Response
    {
        [$lang, $slug] = $this->extractLangSlug($req);
        if ($lang === null) {
            return Response::redirect($req->url('pages'));
        }
        $entries = $this->history->listFor($lang, $slug);
        $rows = [];
        foreach ($entries as $e) {
            $human = '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})/', $e['ts'], $m) === 1) {
                $human = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} UTC";
            }
            $rows[] = $e + ['human' => $human, 'size_human' => self::humanSize($e['size'])];
        }
        $notice = $req->query('restored') === '1' ? 'Snapshot restored.' : '';
        return Response::html($this->views->page('pages/history', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_HISTORY),
            'LANG'        => $lang,
            'SLUG'        => $slug,
            'ENTRIES'     => $rows,
            'EMPTY'       => $rows === [] ? 'yes' : '',
            'NOTICE'      => $notice,
            'ERROR'       => '',
        ]));
    }

    public function historyRestore(Request $req): Response
    {
        [$lang, $slug] = $this->extractLangSlug($req);
        if ($lang === null) {
            return Response::redirect($req->url('pages'));
        }
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID_HISTORY)) {
            return $this->renderHistoryError($req, $lang, $slug, 'Form expired.');
        }
        $filename = (string)$req->post('file', '');
        if (preg_match(self::HISTORY_FILENAME_REGEX, $filename) !== 1) {
            return $this->renderHistoryError($req, $lang, $slug, 'Invalid snapshot filename.');
        }

        $bytes = $this->history->read($lang, $slug, $filename);
        if ($bytes === null) {
            return $this->renderHistoryError($req, $lang, $slug, 'Snapshot not found.');
        }

        // Sanity-parse before save — corrupt snapshots refuse to restore.
        try {
            $doc = PageDocument::fromSource($bytes);
        } catch (\Throwable $e) {
            return $this->renderHistoryError($req, $lang, $slug, 'Snapshot is corrupt: ' . $e->getMessage());
        }

        try {
            // PageRepository::save automatically snapshots the CURRENT
            // state to history before overwriting — so restore is itself
            // undoable from the same history view.
            $this->pages->save($lang, $slug, $doc);
        } catch (\Throwable $e) {
            $this->audit->record('page.history.restore.fail', $req->clientIp(), $this->username, [
                'lang' => $lang, 'slug' => $slug, 'file' => $filename, 'error' => $e->getMessage(),
            ]);
            return $this->renderHistoryError($req, $lang, $slug, 'Restore failed: ' . $e->getMessage());
        }

        $this->audit->record('page.history.restore', $req->clientIp(), $this->username, [
            'lang' => $lang, 'slug' => $slug, 'file' => $filename,
        ]);
        return Response::redirect(
            $req->url('pages/history') . '?lang=' . urlencode($lang) . '&slug=' . urlencode($slug) . '&restored=1'
        );
    }

    public function historyRaw(Request $req): Response
    {
        [$lang, $slug] = $this->extractLangSlug($req);
        if ($lang === null) {
            return Response::plain('Bad request.', 400);
        }
        $filename = (string)$req->query('file', '');
        if (preg_match(self::HISTORY_FILENAME_REGEX, $filename) !== 1) {
            return Response::plain('Invalid snapshot filename.', 400);
        }
        $bytes = $this->history->read($lang, $slug, $filename);
        if ($bytes === null) {
            return Response::plain('Snapshot not found.', 404);
        }
        return Response::plain($bytes);
    }

    // ============================================================
    // Render helpers
    // ============================================================

    /**
     * @return array{0:?string, 1:string}
     */
    private function extractLangSlug(Request $req): array
    {
        $lang = (string)$req->query('lang', '');
        $slug = (string)$req->query('slug', '');
        if (!Identifiers::isValidLang($lang)) return [null, ''];
        if (!Identifiers::isValidSlug($slug)) return [null, ''];
        return [$lang, $slug];
    }

    private function renderEdit(Request $req, string $lang, string $slug, PageDocument $page, string $error, string $notice): Response
    {
        $allLayouts = (array)CoreConfig::get('content.allowed_layouts', ['default']);
        $currentLayout = is_string($page->header['layout'] ?? null) ? $page->header['layout'] : 'default';
        $layoutOptions = [];
        foreach ($allLayouts as $l) {
            if (!is_string($l)) continue;
            $layoutOptions[] = ['value' => $l, 'label' => $l, 'selected' => $l === $currentLayout];
        }

        $meta = is_array($page->header['meta'] ?? null) ? $page->header['meta'] : [];
        $metaTitle = is_string($meta['title']       ?? null) ? $meta['title']       : '';
        $metaDesc  = is_string($meta['description'] ?? null) ? $meta['description'] : '';

        $hasClipboard = $this->clipboard->has($this->username);
        $blocksHtml = '';
        $allSchemas = $this->schemas->all();
        $blockCount = count($page->blocks);
        foreach ($page->blocks as $i => $block) {
            $schema = $allSchemas[$block->type] ?? null;
            if ($schema === null) {
                $blocksHtml .= $this->views->render('pages/block-unknown', [
                    'INDEX' => (string)$i,
                    'TYPE'  => $block->type,
                ]);
                continue;
            }
            $fieldsHtml = $this->formRenderer->renderBlock(
                'block[' . $i . '][attr]', $schema, $block->attrs, $block->body, $i,
            );
            $preview = self::blockPreview($block);
            $blocksHtml .= $this->views->render('pages/block', [
                'INDEX'         => (string)$i,
                'TYPE'          => $block->type,
                'LABEL'         => $schema->label,
                'PREVIEW'       => $preview,
                'HAS_PREVIEW'   => $preview === '' ? '' : 'yes',
                'FIELDS_HTML'   => $fieldsHtml,
                'IS_FIRST'      => $i === 0 ? 'yes' : '',
                'IS_LAST'       => $i === $blockCount - 1 ? 'yes' : '',
                'HAS_CLIPBOARD' => $hasClipboard ? 'yes' : '',
            ]);
        }

        $blockTypeOptions = [];
        foreach ($allSchemas as $type => $schema) {
            $blockTypeOptions[] = ['value' => $type, 'label' => $type . ' — ' . $schema->label];
        }

        $notice = $notice !== '' ? $notice : ($req->query('saved')   === '1' ? 'Saved.' :
                                              ($req->query('created') === '1' ? 'Page created.' : ''));

        // Asset paths feed the `<datalist>` autocomplete on every
        // image field. Capped at 500 to keep DOM size sane.
        $assetPaths = $this->assetBrowser->allImagePaths(500);

        return Response::html($this->views->page('pages/edit', [
            'BASE'             => $req->basePath(),
            'AUTHED_USER'      => $this->username,
            'CSRF'             => $this->csrf->issue(self::FORM_ID),
            'CSRF_LOGOUT'      => $this->csrf->issue('logout'),
            'LANG'             => $lang,
            'SLUG'             => $slug,
            'LAYOUTS'          => $layoutOptions,
            'META_TITLE'       => $metaTitle,
            'META_DESCRIPTION' => $metaDesc,
            'BLOCKS_HTML'      => $blocksHtml,
            'BLOCK_TYPES'      => $blockTypeOptions,
            'HAS_CLIPBOARD'    => $hasClipboard ? 'yes' : '',
            'ASSET_PATHS'      => $assetPaths,
            'SITE_ROOT'        => $req->siteRoot(),
            'ERROR'            => $error,
            'NOTICE'           => $notice,
        ]));
    }

    private function renderEditError(Request $req, string $lang, string $slug, string $error, ?PageDocument $rawDoc = null): Response
    {
        try {
            $doc = $rawDoc ?? $this->pages->load($lang, $slug);
        } catch (\Throwable) {
            $doc = new PageDocument();
        }
        return $this->renderEdit($req, $lang, $slug, $doc, $error, '');
    }

    /** @return list<array{value:string, label:string, selected:bool}> */
    private function layoutOptions(string $current): array
    {
        $allLayouts = (array)CoreConfig::get('content.allowed_layouts', ['default']);
        $out = [];
        foreach ($allLayouts as $l) {
            if (!is_string($l)) continue;
            $out[] = ['value' => $l, 'label' => $l, 'selected' => $l === $current];
        }
        return $out;
    }

    /** @return list<string> */
    private function knownLangs(): array
    {
        $supported = (array)CoreConfig::get('supported_langs', ['en']);
        $out = [];
        foreach ($supported as $l) {
            if (is_string($l) && Identifiers::isValidLang($l)) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /**
     * Pull a short, human-readable preview string out of a block's
     * attributes — used as the collapsed summary so the operator can
     * tell blocks apart without expanding each one. Falls through a
     * priority list of common title-ish field names; final fallback
     * is the body's first non-blank line.
     */
    private static function blockPreview(Block $block): string
    {
        foreach (['title', 'heading', 'eyebrow', 'name', 'caption'] as $key) {
            $v = $block->attrs[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return self::truncate(trim($v), 100);
            }
        }
        // First scalar string attr, in declaration order.
        foreach ($block->attrs as $v) {
            if (is_string($v) && trim($v) !== '') {
                return self::truncate(trim($v), 100);
            }
        }
        // Fall back to a body excerpt.
        if (is_string($block->body) && trim($block->body) !== '') {
            $firstLine = strtok(trim($block->body), "\n") ?: '';
            return self::truncate($firstLine, 100);
        }
        return '';
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) return $s;
        return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
    }

    private function renderNewError(Request $req, string $lang, string $slug, string $title, string $error): Response
    {
        return Response::html($this->views->page('pages/new', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_NEW),
            'LAYOUTS'     => $this->layoutOptions('default'),
            'LANGS'       => $this->knownLangs(),
            'ERROR'       => $error,
            'LANG'        => $lang,
            'SLUG'        => $slug,
            'TITLE'       => $title,
        ]), 400);
    }

    private function renderRecyclerError(Request $req, string $error): Response
    {
        $entries = $this->recycler->list();
        $rows = [];
        foreach ($entries as $e) {
            $human = '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})$/', $e['deletedAt'], $m) === 1) {
                $human = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
            }
            $rows[] = $e + ['deleted_human' => $human];
        }
        return Response::html($this->views->page('pages/recycler', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_RECYCLER),
            'ENTRIES'     => $rows,
            'EMPTY'       => $rows === [] ? 'yes' : '',
            'NOTICE'      => '',
            'ERROR'       => $error,
        ]), 400);
    }

    private function renderHistoryError(Request $req, string $lang, string $slug, string $error): Response
    {
        $entries = $this->history->listFor($lang, $slug);
        $rows = [];
        foreach ($entries as $e) {
            $human = '';
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})/', $e['ts'], $m) === 1) {
                $human = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} UTC";
            }
            $rows[] = $e + ['human' => $human, 'size_human' => self::humanSize($e['size'])];
        }
        return Response::html($this->views->page('pages/history', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_HISTORY),
            'LANG'        => $lang,
            'SLUG'        => $slug,
            'ENTRIES'     => $rows,
            'EMPTY'       => $rows === [] ? 'yes' : '',
            'NOTICE'      => '',
            'ERROR'       => $error,
        ]), 400);
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) return '';
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
