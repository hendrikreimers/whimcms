<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Config;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\View\Renderer;
use H42\WhimCMS\Config as CoreConfig;
use H42\WhimCMS\Content\Identifiers;

/**
 * Routes + Languages management (Phase 5).
 *
 *   GET  /settings/routes              — table of all language → segment → slug entries
 *   POST /settings/routes              — save (action-dispatched)
 *   GET  /settings/languages           — supported_langs / default_lang
 *   POST /settings/languages           — add / remove / set-default
 *
 * Edits are written back through PhpArrayWriter, which round-trip
 * checks every payload before the rename — a serialiser regression
 * cannot land a broken `routes.php` / `i18n.php` on disk.
 */
final class SettingsController
{
    private const FORM_ID_ROUTES = 'settings-routes';
    private const FORM_ID_LANGS  = 'settings-langs';

    public function __construct(
        private PhpArrayWriter $writer,
        private string $coreConfigDir,
        private string $i18nDir,             // <theme>/i18n
        private Csrf $csrf,
        private Renderer $views,
        private AuditLog $audit,
        private string $username,
    ) {
    }

    // ============================================================
    // Routes
    // ============================================================

    public function routesForm(Request $req): Response
    {
        return $this->renderRoutes($req, $this->loadRoutes(), '', $req->query('saved') === '1' ? 'Saved.' : '');
    }

    public function routesSave(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID_ROUTES)) {
            return $this->renderRoutes($req, $this->loadRoutes(), 'Form expired.', '');
        }
        $rows = $req->postAll()['route'] ?? [];
        if (!is_array($rows)) $rows = [];
        $newRoutes = $this->rowsToRoutes($rows);

        // Defensive: never silently drop a supported language's entire
        // bucket. If it's empty after editing, persist it as an empty
        // map so the routes editor still surfaces the lang on next view.
        $supported = (array)CoreConfig::get('supported_langs', []);
        foreach ($supported as $l) {
            if (is_string($l) && !isset($newRoutes[$l])) {
                $newRoutes[$l] = [];
            }
        }

        try {
            $this->writer->write(PhpArrayWriter::TARGET_ROUTES, ['routes' => $newRoutes]);
        } catch (\Throwable $e) {
            return $this->renderRoutes($req, $newRoutes, 'Save failed: ' . $e->getMessage(), '');
        }
        $this->audit->record('settings.routes.save', $req->clientIp(), $this->username);
        return Response::redirect($req->url('settings/routes') . '?saved=1');
    }

    /**
     * @param array<string, mixed> $rows
     * @return array<string, array<string, string>>
     */
    private function rowsToRoutes(array $rows): array
    {
        $out = [];
        foreach ($rows as $lang => $entries) {
            if (!is_string($lang) || !Identifiers::isValidLang($lang)) continue;
            if (!is_array($entries)) continue;
            $clean = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) continue;
                $segment = (string)($entry['segment'] ?? '');
                $slug    = (string)($entry['slug']    ?? '');
                $remove  = (string)($entry['remove']  ?? '');
                if ($remove === 'yes') continue;
                if ($slug === '') continue; // skip blank slug rows
                // Segment shape is route-specific, not an identifier; keep
                // its regex inline. Slug uses the shared identifier shape.
                if ($segment !== '' && preg_match('#^[a-zA-Z0-9_/-]{1,64}$#', $segment) !== 1) continue;
                if (!Identifiers::isValidSlug($slug)) continue;
                $clean[$segment] = $slug;
            }
            $out[$lang] = $clean;
        }
        return $out;
    }

    /**
     * Pre-compute every input's `name=` attribute server-side. Empty
     * `route[en][][...]` does NOT work because PHP auto-indexes each
     * `[]` independently — `segment` and `slug` would land in
     * different rows. Explicit indices keep them paired.
     *
     * @param array<string, array<string, string>> $routes
     */
    private function renderRoutes(Request $req, array $routes, string $error, string $notice): Response
    {
        // Always include every supported_lang, even if routes.php is
        // missing a bucket for it (e.g. fresh-added language).
        $supported = (array)CoreConfig::get('supported_langs', ['en']);
        $supported = array_values(array_filter($supported, 'is_string'));
        foreach ($supported as $l) {
            if (!isset($routes[$l])) {
                $routes[$l] = [];
            }
        }
        ksort($routes);

        $langGroups = [];
        foreach ($routes as $lang => $map) {
            $rows = [];
            $idx = 0;
            foreach ($map as $segment => $slug) {
                $rows[] = [
                    'segment'      => $segment,
                    'slug'         => $slug,
                    'name_segment' => "route[{$lang}][{$idx}][segment]",
                    'name_slug'    => "route[{$lang}][{$idx}][slug]",
                    'name_remove'  => "route[{$lang}][{$idx}][remove]",
                    'is_new'       => 'no',
                ];
                $idx++;
            }
            // One trailing empty row for adding a new entry — give it
            // a high-water index that cannot collide with anything
            // already present.
            $newIdx = $idx;
            $rows[] = [
                'segment'      => '',
                'slug'         => '',
                'name_segment' => "route[{$lang}][{$newIdx}][segment]",
                'name_slug'    => "route[{$lang}][{$newIdx}][slug]",
                'name_remove'  => '',
                'is_new'       => 'yes',
            ];
            $langGroups[] = ['lang' => $lang, 'rows' => $rows];
        }
        return Response::html($this->views->page('settings/routes', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_ROUTES),
            'LANG_GROUPS' => $langGroups,
            'ERROR'       => $error,
            'NOTICE'      => $notice,
        ]));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadRoutes(): array
    {
        $data = (array)CoreConfig::get('routes', []);
        $out = [];
        foreach ($data as $lang => $map) {
            if (!is_string($lang) || !is_array($map)) continue;
            $clean = [];
            foreach ($map as $seg => $slug) {
                if (is_string($seg) && is_string($slug)) {
                    $clean[$seg] = $slug;
                }
            }
            $out[$lang] = $clean;
        }
        return $out;
    }

    // ============================================================
    // Languages
    // ============================================================

    public function languagesForm(Request $req): Response
    {
        return $this->renderLangs($req, '', $req->query('saved') === '1' ? 'Saved.' : '');
    }

    public function languagesSave(Request $req): Response
    {
        if (!$this->csrf->validateFromRequest($req, self::FORM_ID_LANGS)) {
            return $this->renderLangs($req, 'Form expired.', '');
        }
        $action = (string)$req->post('action', '');
        $supported = (array)CoreConfig::get('supported_langs', ['en']);
        $supported = array_values(array_filter($supported, 'is_string'));
        $default   = (string)CoreConfig::get('default_lang', $supported[0] ?? 'en');
        $detect    = (bool)CoreConfig::get('detect_lang', true);

        if ($action === 'add') {
            $lang = trim((string)$req->post('_new_lang', ''));
            if (!Identifiers::isValidLang($lang)) {
                return $this->renderLangs($req, "Invalid language code '{$lang}'.", '');
            }
            if (in_array($lang, $supported, true)) {
                return $this->renderLangs($req, "Language '{$lang}' is already supported.", '');
            }
            $supported[] = $lang;
            $this->ensureI18nFile($lang);
            $this->ensureRouteBucket($lang);
        } elseif (str_starts_with($action, 'remove:')) {
            $lang = substr($action, 7);
            if (!Identifiers::isValidLang($lang)) {
                return $this->renderLangs($req, 'Invalid action.', '');
            }
            if (count($supported) <= 1) {
                return $this->renderLangs($req, 'Cannot remove the last supported language.', '');
            }
            $supported = array_values(array_filter($supported, fn($l) => $l !== $lang));
            if (!in_array($default, $supported, true)) {
                $default = $supported[0];
            }
        } elseif ($action === 'set-default') {
            $lang = (string)$req->post('_default_lang', '');
            if (!in_array($lang, $supported, true)) {
                return $this->renderLangs($req, 'Default lang must be one of supported.', '');
            }
            $default = $lang;
        } elseif ($action === 'set-detect') {
            $detect = (string)$req->post('_detect', '') === 'true';
        } else {
            return $this->renderLangs($req, 'Unknown action.', '');
        }

        try {
            $this->writer->write(PhpArrayWriter::TARGET_I18N, [
                'supported_langs' => $supported,
                'default_lang'    => $default,
                'detect_lang'     => $detect,
            ]);
        } catch (\Throwable $e) {
            return $this->renderLangs($req, 'Save failed: ' . $e->getMessage(), '');
        }
        $this->audit->record('settings.langs.save', $req->clientIp(), $this->username, ['action' => $action]);
        return Response::redirect($req->url('settings/languages') . '?saved=1');
    }

    private function renderLangs(Request $req, string $error, string $notice): Response
    {
        $supported = (array)CoreConfig::get('supported_langs', ['en']);
        $supported = array_values(array_filter($supported, 'is_string'));
        $default   = (string)CoreConfig::get('default_lang', $supported[0] ?? 'en');
        $detect    = (bool)CoreConfig::get('detect_lang', true);

        $rows = [];
        foreach ($supported as $l) {
            $rows[] = [
                'lang'         => $l,
                'is_default'   => $l === $default ? 'yes' : '',
                'can_remove'   => count($supported) > 1 ? 'yes' : '',
            ];
        }
        return Response::html($this->views->page('settings/languages', [
            'BASE'        => $req->basePath(),
            'AUTHED_USER' => $this->username,
            'CSRF_LOGOUT' => $this->csrf->issue('logout'),
            'CSRF'        => $this->csrf->issue(self::FORM_ID_LANGS),
            'LANGS'       => $rows,
            'DEFAULT'     => $default,
            'DETECT'      => $detect ? 'yes' : '',
            'ERROR'       => $error,
            'NOTICE'      => $notice,
        ]));
    }

    /**
     * Add an empty `routes[<lang>] = []` slot in routes.php so the
     * routes editor immediately exposes a row for the new language.
     */
    private function ensureRouteBucket(string $lang): void
    {
        $routes = (array)CoreConfig::get('routes', []);
        if (isset($routes[$lang])) return;
        $routes[$lang] = [];
        try {
            $this->writer->write(PhpArrayWriter::TARGET_ROUTES, ['routes' => $routes]);
        } catch (\Throwable) {
            // Non-fatal: operator can add it manually via the routes editor.
        }
    }

    /**
     * Create an empty i18n/<lang>.json by copying the default-lang
     * dictionary as a starting point. Operator translates each value.
     */
    private function ensureI18nFile(string $newLang): void
    {
        $target = $this->i18nDir . DIRECTORY_SEPARATOR . $newLang . '.json';
        if (is_file($target)) return;
        $defaultLang = (string)CoreConfig::get('default_lang', 'en');
        $template = $this->i18nDir . DIRECTORY_SEPARATOR . $defaultLang . '.json';
        $bytes = is_file($template) ? @file_get_contents($template) : '{}';
        if ($bytes === false) $bytes = '{}';
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) return;
        @chmod($tmp, 0o644);
        if (!@rename($tmp, $target)) @unlink($tmp);
    }
}
