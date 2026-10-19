<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Security\FleetUser;

/**
 * Membership from the project_access rows, matched on the username Authelia asserts. A user the database does not
 * know (or whose row was deleted) is no member, whatever groups the proxy sends.
 */
final readonly class ProjectAccessMembership implements ProjectMembership
{
    public function __construct(private ProjectAccessRepository $access)
    {
    }

    public function isMember(FleetUser $user, Project $project): bool
    {
        return $this->access->isMember($user->getUserIdentifier(), $project);
    }
}
