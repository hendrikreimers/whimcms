<?php
declare(strict_types=1);

namespace H42\WhimCMS\Content;

/**
 * Single source of truth for the regex shapes that gate the content
 * subsystem's identifiers (slug, language code, block type) and the
 * structural delimiter of the .md source format (block opener line).
 *
 * Before this class existed, the same patterns lived in:
 *   - lib/WhimCMS/Content/PageLoader.php       (public-side parser)
 *   - whimadmin/.../Content/PageDocument.php   (admin-side parser)
 *   - whimadmin/.../Content/PageRepository.php (admin-side I/O)
 *   - whimadmin/.../Content/Recycler.php       (admin-side soft-delete)
 *   - whimadmin/.../Content/HistoryStore.php   (admin-side versions)
 *   - whimadmin/.../Content/PagesController.php (admin-side editor)
 *   - whimadmin/.../Config/SettingsController.php (admin-side routes form)
 *   - whimadmin/.../Config/PhpArrayWriter.php  (admin-side config writer)
 *
 * Drift between any pair would silently let one boundary accept what
 * another rejects (e.g. the editor saves a slug the public renderer
 * cannot load). Centralising the patterns here makes a tightening
 * land everywhere on one save and the dependency graph self-evident.
 *
 * Boundary properties (kept stable across versions):
 *
 *   slug         ^[a-zA-Z][a-zA-Z0-9_-]{0,40}$    — author-controlled
 *                identifier in routes.php and content/<lang>/<slug>.md.
 *                Mixed case allowed for camelCase ("landingFeature");
 *                charset constrained so a slug cannot escape into a
 *                filesystem path. realpath-containment in callers
 *                stays mandatory.
 *
 *   lang         ^[a-z]{2}$                       — ISO-639-1 alpha-2,
 *                lower-case. Two-letter cap matches every supported
 *                downstream consumer (paths, JSON dictionaries, Accept-
 *                Language matcher).
 *
 *   block-type   ^[a-z][a-z0-9-]{0,40}$           — partial filename
 *                slug (kebab-case). Same shape as the partial filename
 *                under templates/partials/blocks/<type>.html so a
 *                bidirectional lookup is unambiguous.
 *
 *   block-open   ^::: <type>$                     — block opener line
 *                in content sources. Captures the type name as group 1.
 *
 * The static helpers `isValid…()` and `assert…()` are conveniences for
 * the most common call sites; direct preg_match against the pattern
 * constants is also fine where appropriate (e.g. inside scandir loops
 * that filter many candidates per request).
 */
final class Identifiers
{
    public const SLUG_PATTERN       = '/^[a-zA-Z][a-zA-Z0-9_-]{0,40}$/';
    public const LANG_PATTERN       = '/^[a-z]{2}$/';
    public const BLOCK_TYPE_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';
    public const BLOCK_OPEN_PATTERN = '/^::: ([a-z][a-z0-9-]{0,40})$/';

    public static function isValidSlug(string $s): bool
    {
        return preg_match(self::SLUG_PATTERN, $s) === 1;
    }

    public static function isValidLang(string $s): bool
    {
        return preg_match(self::LANG_PATTERN, $s) === 1;
    }

    public static function isValidBlockType(string $s): bool
    {
        return preg_match(self::BLOCK_TYPE_PATTERN, $s) === 1;
    }

    public static function assertSlug(string $s): void
    {
        if (!self::isValidSlug($s)) {
            throw new \InvalidArgumentException("Invalid slug: {$s}");
        }
    }

    public static function assertLang(string $s): void
    {
        if (!self::isValidLang($s)) {
            throw new \InvalidArgumentException("Invalid language code: {$s}");
        }
    }
}
