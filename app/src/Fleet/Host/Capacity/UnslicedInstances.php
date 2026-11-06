<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\FleetPaths;

/**
 * The instances that do not run under a Fleet CPU slice yet (FLE-203, spec 3): deployed before the feature, they have no
 * `.ddev/docker-compose.fleet-cgroup.yaml` until redeployed or restarted, so no allotment limits them. Read-only; an
 * instance on its way out is not listed.
 */
final readonly class UnslicedInstances
{
    public const string CGROUP_FILE = '.ddev/docker-compose.fleet-cgroup.yaml';

    public function __construct(
        private InstanceRepository $instances,
        private FleetPaths $paths,
    ) {
    }

    /**
     * @return list<array{id: string, project: string, template: string, state: string}>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Destroying === $instance->state()) {
                continue;
            }
            if (is_file($this->paths->instanceDir($instance->id()).'/'.self::CGROUP_FILE)) {
                continue;
            }
            $rows[] = [
                'id' => $instance->id(),
                'project' => $instance->project()->name(),
                'template' => $instance->templateName(),
                'state' => $instance->state()->value,
            ];
        }

        return $rows;
    }
}
