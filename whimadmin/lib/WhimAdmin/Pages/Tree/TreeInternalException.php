<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Carries a structural / internal-only error from inside TreeMutator
 * with a separated public message and debug detail.
 *
 * The split exists because internal errors like `spliceIn missing
 * parent at depth N (path 'X/Y'; container has K items)` leak the
 * tree-shape, index counts, and traversal state — information that
 * is useful for an attacker probing for tree structure but irrelevant
 * for the editor user, who only needs to know "the page tree changed,
 * please reload and retry".
 *
 * The controller gates exposure on the admin's `debug` config flag:
 * production responses see only `publicMessage`; debug builds see the
 * concatenated form. The audit log always records the full message so
 * forensic review keeps the diagnostic detail.
 */
final class TreeInternalException extends \RuntimeException
{
    public function __construct(
        public readonly string $publicMessage,
        public readonly ?string $debugDetail = null,
    ) {
        parent::__construct(
            $debugDetail !== null && $debugDetail !== ''
                ? $publicMessage . ' [' . $debugDetail . ']'
                : $publicMessage
        );
    }
}
