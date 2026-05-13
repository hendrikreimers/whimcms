<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages;

use H42\WhimAdmin\Audit\Log as AuditLog;
use H42\WhimAdmin\Http\Csrf;
use H42\WhimAdmin\Http\Request;
use H42\WhimAdmin\Http\Response;
use H42\WhimAdmin\Pages\Tree\TreeAggregator;
use H42\WhimAdmin\Pages\Tree\TreeInternalException;
use H42\WhimAdmin\Pages\Tree\TreeMutator;
use H42\WhimAdmin\Pages\Tree\TreeVersionConflictException;
use H42\WhimCMS\Content\Identifiers;

/**
 * JSON POST endpoints for tree mutations.
 *
 *   POST /pages/tree/create     create a new node
 *   POST /pages/tree/move       reposition an existing node
 *   POST /pages/tree/rename     change a slug-type node's slug
 *   POST /pages/tree/retype     change a node's type
 *   POST /pages/tree/delete     remove a node (soft-delete .md, route)
 *   POST /pages/tree/save       persist edited field values
 *
 * Every endpoint:
 *   - Validates CSRF (X-CSRF-Token header preferred; `_csrf` in JSON
 *     body accepted as fallback).
 *   - Parses + sanitises JSON body via Request::jsonBody().
 *   - Validates required fields with explicit shape checks.
 *   - Delegates the actual mutation to TreeMutator.
 *   - Translates the mutator's typed errors to HTTP responses
 *     (400 for validation, 409 for tree-version conflicts, 500 for
 *     anything else).
 *   - Emits an audit-log event for every attempt (success or fail).
 *
 * Response shape on success:
 *   { ok: true, treeVersion: "<new>", ...operation-specific result }
 *
 * Response shape on error:
 *   { error: "<class>", message: "<human-readable>" }
 *
 * The new treeVersion is computed AFTER the mutation completes so the
 * UI can immediately re-arm for the next operation without an
 * intermediate GET /pages/tree.
 */
final class PagesTreeMutationController
{
    private const FORM_ID = 'tree';

    public function __construct(
        private TreeMutator           $mutator,
        private PageTypeSchemaLoader  $pageTypes,
        private PageMetaFormDecoder   $decoder,
        private TreeAggregator        $aggregator,
        private Csrf                  $csrf,
        private AuditLog              $audit,
        private string                $username,
        private bool                  $debug = false,
    ) {
    }

    public function create(Request $req): Response
    {
        return $this->run($req, 'create', function (array $body): array {
            $lang             = $this->requireLang($body);
            $section          = $this->requireSection($body);
            $parentIndexPath  = $this->optionalIndexPath($body, 'parentIndexPath');
            $beforeIndex      = $this->requireInt($body, 'beforeIndex');
            $type             = $this->requireType($body);
            $version          = $this->requireString($body, 'treeVersion');
            if ($section === 'unsorted') {
                throw new \RuntimeException("Cannot create directly in the 'unsorted' bucket.");
            }
            return $this->mutator->create($lang, $section, $parentIndexPath, $beforeIndex, $type, $version);
        });
    }

    public function move(Request $req): Response
    {
        return $this->run($req, 'move', function (array $body): array {
            $lang              = $this->requireLang($body);
            $fromSection       = $this->requireSection($body, 'fromSection');
            $fromIndexPath     = $this->requireIndexPath($body, 'fromIndexPath');
            $toSection         = $this->requireSection($body, 'toSection');
            $toParentIndexPath = $this->optionalIndexPath($body, 'toParentIndexPath');
            $toBeforeIndex     = $this->requireInt($body, 'toBeforeIndex');
            $version           = $this->requireString($body, 'treeVersion');
            return $this->mutator->move(
                $lang, $fromSection, $fromIndexPath,
                $toSection, $toParentIndexPath, $toBeforeIndex, $version,
            );
        });
    }

    public function rename(Request $req): Response
    {
        return $this->run($req, 'rename', function (array $body): array {
            $lang      = $this->requireLang($body);
            $section   = $this->requireSection($body);
            $indexPath = $this->requireIndexPath($body, 'indexPath');
            $newSlug   = $this->requireString($body, 'newSlug');
            $version   = $this->requireString($body, 'treeVersion');
            return $this->mutator->rename($lang, $section, $indexPath, $newSlug, $version);
        });
    }

    public function retype(Request $req): Response
    {
        return $this->run($req, 'retype', function (array $body): array {
            $lang      = $this->requireLang($body);
            $section   = $this->requireSection($body);
            $indexPath = $this->requireIndexPath($body, 'indexPath');
            $newType   = $this->requireType($body, 'newType');
            $version   = $this->requireString($body, 'treeVersion');
            if ($section === 'unsorted') {
                throw new \RuntimeException("Retype is not available for unsorted entries.");
            }
            return $this->mutator->retype($lang, $section, $indexPath, $newType, $version);
        });
    }

    public function delete(Request $req): Response
    {
        return $this->run($req, 'delete', function (array $body): array {
            $lang      = $this->requireLang($body);
            $section   = $this->requireSection($body);
            $indexPath = $this->requireIndexPath($body, 'indexPath');
            $version   = $this->requireString($body, 'treeVersion');
            return $this->mutator->delete($lang, $section, $indexPath, $version);
        });
    }

    public function save(Request $req): Response
    {
        return $this->run($req, 'save', function (array $body): array {
            $lang      = $this->requireLang($body);
            $section   = $this->requireSection($body);
            $indexPath = $this->requireIndexPath($body, 'indexPath');
            $itemType  = $this->requireType($body, 'type');
            $valuesRaw = $body['values'] ?? null;
            if (!is_array($valuesRaw)) {
                throw new \RuntimeException("Missing 'values' object.");
            }
            $version = $this->requireString($body, 'treeVersion');

            $pageType = $this->pageTypes->get($itemType);
            if ($pageType === null) {
                throw new \RuntimeException("Unknown page-type schema: '{$itemType}'.");
            }
            $bucketed = $this->decoder->decode($pageType, $valuesRaw);

            return $this->mutator->save($lang, $section, $indexPath, $bucketed, $version);
        });
    }

    // ============================================================
    // Generic per-endpoint wrapper
    // ============================================================

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $op
     */
    private function run(Request $req, string $opName, callable $op): Response
    {
        $auditOk   = 'page.tree.' . $opName;
        $auditFail = 'page.tree.' . $opName . '.fail';

        $body = $req->jsonBody();
        if (!$this->validateCsrf($req, $body)) {
            $this->audit->record('page.tree.csrf.invalid', $req->clientIp(), $this->username, ['op' => $opName]);
            return $this->jsonError(403, 'csrf', 'Invalid or missing CSRF token.');
        }

        try {
            $result = $op($body);
        } catch (TreeVersionConflictException $e) {
            $this->audit->record('page.tree.version-conflict', $req->clientIp(), $this->username, ['op' => $opName]);
            // 409 carries the current version so the client can prompt
            // for reload without an extra GET.
            return Response::json([
                'error'           => 'tree-version-conflict',
                'message'         => $e->getMessage(),
                'currentVersion'  => $this->aggregator->build()->version,
            ], 409);
        } catch (TreeInternalException $e) {
            // Internal / structural errors carry both a user-safe
            // message and a debug detail. The full string lands in the
            // audit log unconditionally so forensic review keeps the
            // diagnostic; the client sees only the public message
            // unless `whimadmin/config/app.php → debug` is on.
            $this->audit->record($auditFail, $req->clientIp(), $this->username, [
                'op'    => $opName,
                'error' => $e->getMessage(),
            ]);
            $public = $this->debug ? $e->getMessage() : $e->publicMessage;
            return $this->jsonError(400, 'mutation', $public);
        } catch (\InvalidArgumentException $e) {
            $this->audit->record($auditFail, $req->clientIp(), $this->username, ['op' => $opName, 'error' => $e->getMessage()]);
            return $this->jsonError(400, 'validation', $e->getMessage());
        } catch (\RuntimeException $e) {
            // Generic RuntimeException paths carry user-actionable
            // validation messages (slug collisions, allowlist
            // rejections, etc.). These are deliberately surfaced — the
            // user needs the specifics to fix the input.
            $this->audit->record($auditFail, $req->clientIp(), $this->username, ['op' => $opName, 'error' => $e->getMessage()]);
            return $this->jsonError(400, 'mutation', $e->getMessage());
        } catch (\Throwable $e) {
            $this->audit->record($auditFail, $req->clientIp(), $this->username, ['op' => $opName, 'error' => $e->getMessage()]);
            return $this->jsonError(500, 'internal', $this->debug ? $e->getMessage() : 'Internal error.');
        }

        $this->audit->record($auditOk, $req->clientIp(), $this->username, [
            'op'       => $opName,
            // Slug + indexPath + url are useful audit context — all
            // three are already shape-validated by the time we reach
            // success. `url` is operation-specific (saveImpl + moveImpl
            // surface it; rename/retype/create/delete don't) so its
            // absence is normal.
            'slug'      => isset($result['slug']) ? (string)$result['slug'] : null,
            'indexPath' => isset($result['indexPath']) ? (string)$result['indexPath'] : null,
            'url'       => isset($result['url']) ? (string)$result['url'] : null,
        ]);
        return Response::json([
            'ok'          => true,
            'treeVersion' => $this->aggregator->build()->version,
        ] + $result);
    }

    private function jsonError(int $status, string $cls, string $message): Response
    {
        return Response::json(['error' => $cls, 'message' => $message], $status);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateCsrf(Request $req, array $body): bool
    {
        $token = $req->header('X-CSRF-Token');
        if ($token === '' && is_string($body['_csrf'] ?? null)) {
            $token = (string)$body['_csrf'];
        }
        return $this->csrf->validate($token, self::FORM_ID);
    }

    // ============================================================
    // Body-extraction helpers (each throws InvalidArgumentException
    // or RuntimeException with a clear message that the run() wrapper
    // translates to a 400 response)
    // ============================================================

    /** @param array<string, mixed> $body */
    private function requireString(array $body, string $key): string
    {
        $v = $body[$key] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException("Missing required string field '{$key}'.");
        }
        return $v;
    }

    /** @param array<string, mixed> $body */
    private function requireInt(array $body, string $key): int
    {
        $v = $body[$key] ?? null;
        if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            return (int)$v;
        }
        if (is_int($v)) {
            return $v;
        }
        throw new \RuntimeException("Missing required integer field '{$key}'.");
    }

    /** @param array<string, mixed> $body */
    private function requireLang(array $body): string
    {
        $v = $this->requireString($body, 'lang');
        if (!Identifiers::isValidLang($v)) {
            throw new \RuntimeException("Bad language code '{$v}'.");
        }
        return $v;
    }

    /** @param array<string, mixed> $body */
    private function requireSection(array $body, string $key = 'section'): string
    {
        $v = $this->requireString($body, $key);
        if ($v === 'unsorted') return $v;
        if (preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $v) !== 1) {
            throw new \RuntimeException("Bad section key '{$v}'.");
        }
        return $v;
    }

    /** @param array<string, mixed> $body */
    private function requireIndexPath(array $body, string $key): string
    {
        $v = $this->requireString($body, $key);
        if (preg_match('/^\d+(\/\d+)*$/', $v) !== 1) {
            throw new \RuntimeException("Bad indexPath '{$v}'.");
        }
        return $v;
    }

    /** @param array<string, mixed> $body */
    private function optionalIndexPath(array $body, string $key): string
    {
        $v = $body[$key] ?? '';
        if (!is_string($v)) return '';
        if ($v !== '' && preg_match('/^\d+(\/\d+)*$/', $v) !== 1) {
            throw new \RuntimeException("Bad {$key} '{$v}'.");
        }
        return $v;
    }

    /** @param array<string, mixed> $body */
    private function requireType(array $body, string $key = 'type'): string
    {
        $v = $this->requireString($body, $key);
        if (!in_array($v, ['slug', 'href', 'anchor', 'folder'], true)) {
            throw new \RuntimeException("Unknown type '{$v}'.");
        }
        return $v;
    }
}
