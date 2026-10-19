<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Security\FleetUser;

/**
 * Whether a non-admin user may operate a project. The implementation is ProjectAccessMembership (project_access rows).
 */
interface ProjectMembership
{
    public function isMember(FleetUser $user, Project $project): bool;
}
