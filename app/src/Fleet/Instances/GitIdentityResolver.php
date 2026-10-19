<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;

/**
 * The git identity of a project's instances: none when disabled, the project's own name and email when overridden
 * (a blank one falls back to the host default), the host default otherwise.
 */
final readonly class GitIdentityResolver
{
    public function __construct(private HostGitIdentity $host)
    {
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public function forProject(Project $project): ?array
    {
        if (GitBotMode::Disabled === $project->gitBotMode()) {
            return null;
        }
        $default = $this->host->default();
        if (GitBotMode::Override !== $project->gitBotMode()) {
            return $default;
        }

        $name = self::nonBlank($project->gitBotName()) ?? $default['name'] ?? null;
        $email = self::nonBlank($project->gitBotEmail()) ?? $default['email'] ?? null;

        return null === $name || null === $email ? null : ['name' => $name, 'email' => $email];
    }

    private static function nonBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
