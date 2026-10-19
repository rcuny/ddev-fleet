<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;

/**
 * For tests with an entity manager in $this->em: a saved project plus instances in the given states.
 */
trait InstanceFixtures
{
    private function projectNamed(string $name): Project
    {
        $projects = new ProjectRepository($this->em);
        $project = $projects->findByName($name);
        if (null === $project) {
            $project = new Project($name, \sprintf('https://git.example.com/acme/%s.git', $name));
            $projects->save($project);
        }

        return $project;
    }

    /**
     * Labels are i1, i2, ... so the ids are `<project>--i1`, `<project>--i2` (the FakeInstanceGuard's scheme).
     *
     * @param list<InstanceState> $states
     *
     * @return list<Instance>
     */
    private function instancesOf(Project $project, array $states): array
    {
        $instances = [];
        foreach ($states as $i => $state) {
            $instance = new Instance($project, 'i'.($i + 1), 'full', 'develop', 'admin');
            $instance->changeState($state);
            $instances[] = $instance;
        }
        new InstanceRepository($this->em)->createMany($instances);

        return $instances;
    }
}
