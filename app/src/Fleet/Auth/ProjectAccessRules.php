<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\Project\ProjectAccessRepository;
use App\Fleet\System\AccessRule;
use App\Fleet\System\AccessRuleSource;

/**
 * One Authelia rule per project, from the stored project_access rows: administrators (the admin group) may open every
 * project's sites, a member only the sites (and hostname aliases) of their projects.
 */
final readonly class ProjectAccessRules implements AccessRuleSource
{
    public function __construct(
        private ProjectAccessRepository $access,
        private AdminGroup $admins,
    ) {
    }

    public function rules(): array
    {
        $hostnames = $this->access->hostnamesByProject();
        $rules = [];
        foreach ($this->access->membersByProject() as $project => $usernames) {
            $rules[] = new AccessRule($project, $this->admins->name(), $usernames, $hostnames[$project] ?? []);
        }

        return $rules;
    }
}
