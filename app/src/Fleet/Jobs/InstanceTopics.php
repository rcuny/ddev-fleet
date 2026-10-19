<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The private Mercure topics that carry instance changes: one per project, `/instances/<project>`, so a member's token
 * only authorises the projects they may operate. An administrator may operate every project, so gets them all (a project
 * created while the page is open reaches them with the next page load or poll).
 */
final readonly class InstanceTopics
{
    public function __construct(
        private ProjectRepository $projects,
        private AuthorizationCheckerInterface $auth,
    ) {
    }

    public static function of(string $project): string
    {
        return '/instances/'.$project;
    }

    /**
     * The topic of the project an instance id (`<project>--<label>`) belongs to.
     */
    public static function ofInstance(string $instanceId): string
    {
        return self::of(explode('--', $instanceId, 2)[0]);
    }

    /**
     * @return list<string> empty when the user may operate no project
     */
    public function forCurrentUser(): array
    {
        $topics = [];
        foreach ($this->projects->all() as $project) {
            if ($this->auth->isGranted(ProjectVoter::OPERATE, $project)) {
                $topics[] = self::of($project->name());
            }
        }

        return $topics;
    }
}
