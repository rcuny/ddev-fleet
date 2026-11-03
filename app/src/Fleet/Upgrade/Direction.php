<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

enum Direction: string
{
    case Up = 'up';
    case Down = 'down';
    case Same = 'same';
    /** The installed version is not a release (a branch install, or dev) or there is no target to compare. */
    case Unknown = 'unknown';
}
