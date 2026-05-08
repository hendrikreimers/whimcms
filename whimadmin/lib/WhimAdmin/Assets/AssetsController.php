<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Assets;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\View\Renderer;

/**
 * Asset-management controller (Phase 6).
 *
 *   GET  /assets?dir=<path>            browse directory
 *   POST /assets/upload?dir=<path>     upload file
 *   POST /assets/mkdir?dir=<path>      create subdirectory
 *   POST /assets/rename?path=<path>    rename item
 *   POST /assets/delete?path=<path>    move item to .recycler/
 *   GET  /assets/recycler              list recycler
 *   POST /assets/recycler/purge        empty recycler
 */
final class AssetsController
{
    private const FORM_ID = 'assets';

    public function __construct(
        private AssetBrowser $browser,
        private Csrf $csrf,
        private Renderer $views,
        private AuditLog $audit,
        private string $username,
    ) {
    }

    public function browse(Request $req): Response
    {
        $dir = trim((string)$req->query('dir', ''), '/');
        try {
            $listing = $this->browser->list($dir);
        } catch (\Throwable $e) {
            return $this->renderError($req, 'Cannot list directory: ' . $e->getMessage());
        }
        $assetBase = $req->siteRoot() . '/assets';
        $entries = [];
        foreach ($listing['entries'] as $e) {
            $isImage = $e['type'] === 'file' && self::isImageExt($e['name']);
            $entries[] = $e + [
                'size_human'  => self::humanSize($e['size']),
                'mtime_human' => $e['mtime'] === 0 ? '' : gmdate('Y-m-d H:i', $e['mtime']),
                'url'         => $e['type'] === 'file' ? $assetBase . '/' . $e['path'] : '',
                'is_image'    => $isImage ? 'yes' : '',
            ];
        }
        $notice = $req->query('uploaded') === '1' ? 'Uploaded.' :
                  ($req->query('deleted') === '1' ? 'Moved to recycler.' :
                  ($req->query('renamed') === '1' ? 'Renamed.' :
                  ($req->query('mkdir') === '1' ? 'Directory created.' : '')));
        return Response::html($this->views->page('assets/list', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID),
            'DIR'         => $listing['dir'],
            'PARENT'      => $listing['parent'] ?? '',
            'HAS_PARENT'  => $listing['parent'] !== null ? 'yes' : '',
            'ENTRIES'     => $entries,
            'NOTICE'      => '',
            'INFO'        => $notice,
            'ERROR'       => '',
        ]));
    }

    /**
     * "Safe to preview as `<img>`": browsers render these in image
     * mode (no script execution). SVG is intentionally absent —
     * see `AssetBrowser::EXTENSION_ALLOWLIST` for the rationale.
     */
    private static function isImageExt(string $name): bool
    {
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
    }

    public function upload(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            return $this->renderError($req, 'Form expired.');
        }
        $dir = trim((string)$req->query('dir', ''), '/');
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            return $this->renderError($req, 'No file in upload.');
        }
        try {
            $name = $this->browser->upload($dir, $file);
        } catch (\Throwable $e) {
            return $this->renderError($req, 'Upload failed: ' . $e->getMessage());
        }
        $this->audit->record('asset.upload', $req->clientIp(), $this->username, ['dir' => $dir, 'name' => $name]);
        return Response::redirect($req->url('assets') . '?dir=' . rawurlencode($dir) . '&uploaded=1');
    }

    public function mkdir(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            return $this->renderError($req, 'Form expired.');
        }
        $dir  = trim((string)$req->query('dir', ''), '/');
        $name = trim((string)$req->post('name', ''));
        try {
            $this->browser->mkdir($dir, $name);
        } catch (\Throwable $e) {
            return $this->renderError($req, 'mkdir failed: ' . $e->getMessage());
        }
        $this->audit->record('asset.mkdir', $req->clientIp(), $this->username, ['dir' => $dir, 'name' => $name]);
        return Response::redirect($req->url('assets') . '?dir=' . rawurlencode($dir) . '&mkdir=1');
    }

    public function rename(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            return $this->renderError($req, 'Form expired.');
        }
        $path = trim((string)$req->query('path', ''), '/');
        $newName = trim((string)$req->post('new_name', ''));
        try {
            $this->browser->rename($path, $newName);
        } catch (\Throwable $e) {
            return $this->renderError($req, 'Rename failed: ' . $e->getMessage());
        }
        $this->audit->record('asset.rename', $req->clientIp(), $this->username, ['path' => $path, 'new' => $newName]);
        $parent = strpos($path, '/') === false ? '' : substr($path, 0, strrpos($path, '/') ?: 0);
        return Response::redirect($req->url('assets') . '?dir=' . rawurlencode($parent) . '&renamed=1');
    }

    public function delete(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            return $this->renderError($req, 'Form expired.');
        }
        $path = trim((string)$req->query('path', ''), '/');
        try {
            $this->browser->recycle($path);
        } catch (\Throwable $e) {
            return $this->renderError($req, 'Delete failed: ' . $e->getMessage());
        }
        $this->audit->record('asset.delete', $req->clientIp(), $this->username, ['path' => $path]);
        $parent = strpos($path, '/') === false ? '' : substr($path, 0, strrpos($path, '/') ?: 0);
        return Response::redirect($req->url('assets') . '?dir=' . rawurlencode($parent) . '&deleted=1');
    }

    public function recyclerView(Request $req): Response
    {
        $entries = $this->browser->recyclerList();
        return Response::html($this->views->page('assets/recycler', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID),
            'ENTRIES'     => array_map(fn($e) => $e + [
                'size_human' => self::humanSize($e['size']),
                'mtime_human' => $e['mtime'] === 0 ? '' : gmdate('Y-m-d H:i', $e['mtime']),
            ], $entries),
            'NOTICE'      => $req->query('purged') ? 'Recycler emptied.' : '',
        ]));
    }

    public function recyclerPurge(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID)) {
            return Response::redirect($req->url('assets/recycler'));
        }
        $count = $this->browser->recyclerPurge();
        $this->audit->record('asset.recycler.purge', $req->clientIp(), $this->username, ['count' => $count]);
        return Response::redirect($req->url('assets/recycler') . '?purged=1');
    }

    private function renderError(Request $req, string $error): Response
    {
        $dir = trim((string)$req->query('dir', ''), '/');
        try {
            $listing = $this->browser->list($dir);
        } catch (\Throwable) {
            $listing = ['dir' => '', 'parent' => null, 'entries' => []];
        }
        $assetBase = $req->siteRoot() . '/assets';
        $entries = [];
        foreach ($listing['entries'] as $e) {
            $entries[] = $e + [
                'size_human'  => self::humanSize($e['size']),
                'mtime_human' => '',
                'url'         => $e['type'] === 'file' ? $assetBase . '/' . $e['path'] : '',
                'is_image'    => $e['type'] === 'file' && self::isImageExt($e['name']) ? 'yes' : '',
            ];
        }
        return Response::html($this->views->page('assets/list', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID),
            'DIR'         => $listing['dir'],
            'PARENT'      => $listing['parent'] ?? '',
            'HAS_PARENT'  => $listing['parent'] !== null ? 'yes' : '',
            'ENTRIES'     => $entries,
            'NOTICE'      => '',
            'INFO'        => '',
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
