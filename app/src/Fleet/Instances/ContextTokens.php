<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\Projects\Rule\IssueIdPattern;

/**
 * The `[[token]]` values of an instance (the v0 context) and the FLEET_* environment derived from them. Only these
 * context tokens exist: a secret never becomes a token, so it cannot reach a command line.
 */
final readonly class ContextTokens
{
    public function __construct(private InstanceHostnames $hostnames)
    {
    }

    /**
     * @return array<string, string>
     */
    public function for(Instance $instance, Project $project): array
    {
        $tokens = [
            'instance-id' => $instance->id(),
            'project' => $project->name(),
            'instance' => $instance->label(),
            'branch' => $instance->branch(),
            'fleet-domain' => $this->hostnames->domain(),
            'instance-fqdn' => $this->hostnames->primary($instance->id()),
        ];

        $pattern = $project->issueIdRegexp();
        $issue = null === $pattern ? null : IssueIdPattern::extract($pattern, $instance->label(), $instance->branch());
        // Stricter than the pattern's own charset: the value may become a path component or a word of a command.
        if (null !== $issue && 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $issue) && !str_contains($issue, '..')) {
            $tokens['issue-id'] = $issue;
        }

        return $tokens;
    }

    /**
     * @param array<string, string> $tokens
     *
     * @return array<string, string>
     */
    public function env(array $tokens): array
    {
        $env = [
            'FLEET_INSTANCE_ID' => $tokens['instance-id'],
            'FLEET_PROJECT' => $tokens['project'],
            'FLEET_INSTANCE' => $tokens['instance'],
            'FLEET_BRANCH' => $tokens['branch'],
            'FLEET_INSTANCE_FQDN' => $tokens['instance-fqdn'],
            'FLEET_DOMAIN' => $tokens['fleet-domain'],
        ];
        if (isset($tokens['issue-id'])) {
            $env['FLEET_ISSUE_ID'] = $tokens['issue-id'];
        }

        return $env;
    }
}
