<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Reverse-order rollback queue for multi-step tree mutations.
 *
 * Each successful step in a TreeMutator method registers an undo
 * closure with `record()`. On failure later in the same method, the
 * caller invokes `rollback()` which runs the undos in reverse — last
 * successful step first — so each undo sees the on-disk state its
 * forward action produced.
 *
 * Undos are best-effort: any exception inside an undo is swallowed
 * to keep later undos running. The original failure propagates
 * unchanged.
 */
final class TreeMutationLog
{
    /** @var list<callable():void> */
    private array $undos = [];

    public function record(callable $undo): void
    {
        $this->undos[] = $undo;
    }

    public function rollback(): void
    {
        foreach (array_reverse($this->undos) as $undo) {
            try {
                $undo();
            } catch (\Throwable) {
                // Best-effort: swallow so subsequent undos still run.
            }
        }
        $this->undos = [];
    }
}
