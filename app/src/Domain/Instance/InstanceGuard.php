<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectInstanceGuard;

/**
 * The real guard: a project is only deletable while it has no instance rows.
 */
final readonly class InstanceGuard implements ProjectInstanceGuard
{
    public function __construct(private InstanceRepository $instances)
    {
    }

    public function instanceIds(Project $project): array
    {
        return array_map(static fn (Instance $i): string => $i->id(), $this->instances->byProject($project));
    }
}
