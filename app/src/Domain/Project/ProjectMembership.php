<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Security\FleetUser;

/**
 * Whether a non-admin user may operate a project. Real memberships arrive with M0.10; until then nobody is a member.
 */
interface ProjectMembership
{
    public function isMember(FleetUser $user, Project $project): bool;
}
