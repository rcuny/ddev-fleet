<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Security\FleetUser;

final class NoProjectMembership implements ProjectMembership
{
    public function isMember(FleetUser $user, Project $project): bool
    {
        return false;
    }
}
