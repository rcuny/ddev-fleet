<?php

declare(strict_types=1);

namespace App\Domain\Project;

/**
 * Asks whether a project still has instances (App\Domain\Instance\InstanceGuard answers from the database).
 */
interface ProjectInstanceGuard
{
    /**
     * The ids of the project's instances, in a stable order.
     *
     * @return list<string>
     */
    public function instanceIds(Project $project): array;
}
