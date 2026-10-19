<?php

declare(strict_types=1);

namespace App\Domain\Project;

/**
 * Asks whether a project still has instances. Instances arrive in M0.04, which replaces the alias to
 * NoInstanceGuard; until then nothing exists that could block a deletion.
 */
interface ProjectInstanceGuard
{
    /**
     * The ids of the project's instances, in a stable order.
     *
     * @return list<string>
     */
    public function instanceIds(Project $project): array;

    public function instanceCount(Project $project): int;
}
