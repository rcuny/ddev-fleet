<?php

declare(strict_types=1);

namespace App\Api\Read;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Security\Api\ApiTokenUser;

/** Which projects a token sees: those on its list (all of them for an unrestricted read-only token). */
final readonly class ProjectVisibility
{
    public function __construct(private ProjectRepository $projects)
    {
    }

    /**
     * @return list<Project> by name
     */
    public function projects(ApiTokenUser $token): array
    {
        return array_values(array_filter($this->projects->all(), static fn (Project $p): bool => $token->allowsProject($p->name())));
    }

    /**
     * @return list<string>
     */
    public function names(ApiTokenUser $token): array
    {
        return array_map(static fn (Project $p): string => $p->name(), $this->projects($token));
    }
}
