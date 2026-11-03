<?php

declare(strict_types=1);

namespace App\Domain\Ssh;

/**
 * The two key pairs the host owns: `deploy` clones the project repositories, `push` pushes to remotes.
 * The value is the file stem under FLEET_HOME/ssh (see App\Fleet\FleetPaths).
 */
enum SshKeyRole: string
{
    case Deploy = 'deploy';
    case Push = 'push';
}
