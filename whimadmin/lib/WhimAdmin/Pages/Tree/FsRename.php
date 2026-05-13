<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Cross-filesystem-safe rename helper.
 *
 * Tries native `rename()` first; on failure (typically EXDEV across
 * overlayfs / bind mounts / NFS) falls back to `copy + unlink`.
 *
 * Non-atomic in the fallback path — callers must run under flock to
 * avoid concurrent readers seeing both files. Mode bits are NOT
 * preserved by `copy()`; the fallback restores `0o644` because the
 * `.md` files this is used for must be Apache-readable.
 *
 * Extracted from TreeMutator::safeRename. Behaviour-identical.
 */
final class FsRename
{
    /**
     * Returns true on success.
     */
    public static function safe(string $from, string $to): bool
    {
        if (@rename($from, $to)) return true;
        // Fallback: copy + unlink. Works across filesystem boundaries
        // where rename() refuses. `copy()` doesn't preserve mode bits,
        // so re-apply the 0o644 the .md and history files use (Apache
        // must read them for the cached page renderer).
        if (!@copy($from, $to)) return false;
        if (!@unlink($from)) {
            // Forward succeeded partially — clean up the new file
            // so we don't leave duplicates behind.
            @unlink($to);
            return false;
        }
        @chmod($to, 0o644);
        return true;
    }
}
