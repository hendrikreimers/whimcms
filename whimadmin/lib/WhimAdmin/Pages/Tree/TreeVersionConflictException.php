<?php
declare(strict_types=1);

namespace H42\WhimAdmin\Pages\Tree;

/**
 * Thrown by TreeMutator when the client's expected tree version no
 * longer matches current state — overlay or routes.php has been
 * touched since the client last read the tree. The controller
 * translates this into a 409 Conflict response so the editor can
 * prompt the user to reload before saving over fresher data.
 */
final class TreeVersionConflictException extends \RuntimeException
{
}
