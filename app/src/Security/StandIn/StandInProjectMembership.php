<?php

declare(strict_types=1);

namespace App\Security\StandIn;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectMembership;
use App\Security\FleetUser;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development/test only: a user is a member of the project whose name equals one of their groups, so a stand-in
 * member can be given a project without real memberships (M0.10). Anything else is left to the real service.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsDecorator(decorates: ProjectMembership::class)]
final readonly class StandInProjectMembership implements ProjectMembership
{
    public function __construct(
        #[AutowireDecorated]
        private ProjectMembership $inner,
    ) {
    }

    public function isMember(FleetUser $user, Project $project): bool
    {
        return \in_array($project->name(), $user->groups(), true) || $this->inner->isMember($user, $project);
    }
}
