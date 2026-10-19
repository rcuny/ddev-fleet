<?php

declare(strict_types=1);

namespace App\Domain\Project;

final class NoInstanceGuard implements ProjectInstanceGuard
{
    public function instanceIds(Project $project): array
    {
        return [];
    }
}
