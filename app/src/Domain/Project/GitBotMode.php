<?php

declare(strict_types=1);

namespace App\Domain\Project;

/**
 * How a project sets the git identity of its instances: the host default, none at all, or an own name and email.
 */
enum GitBotMode: string
{
    case Inherit = 'inherit';
    case Disabled = 'disabled';
    case Override = 'override';
}
