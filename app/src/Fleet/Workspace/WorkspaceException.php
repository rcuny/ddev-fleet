<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

/**
 * A tmux call that failed or a value the workspace refuses. The message never carries a command line.
 */
final class WorkspaceException extends \RuntimeException
{
}
