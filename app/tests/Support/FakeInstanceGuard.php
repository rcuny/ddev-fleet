<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectInstanceGuard;

/**
 * Stands in for the instance guard of M0.04: a test says how many instances a project has.
 */
final class FakeInstanceGuard implements ProjectInstanceGuard
{
    /** @var array<string, int> */
    private array $counts = [];

    public function set(string $projectName, int $count): void
    {
        $this->counts[$projectName] = $count;
    }

    public function instanceIds(Project $project): array
    {
        $ids = [];
        for ($i = 1; $i <= ($this->counts[$project->name()] ?? 0); ++$i) {
            $ids[] = \sprintf('%s--i%d', $project->name(), $i);
        }

        return $ids;
    }
}
