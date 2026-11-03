<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Port\NamedPort;
use App\Domain\Project\Project;
use App\Domain\Template\Template;

/**
 * A project as plain data, in the 0.x key names: the shape later milestones export and hand to webhooks. Versioning of
 * a written export file belongs to the export (M0.12), not to this array.
 *
 * Read it from a project whose collections are fresh (a new request, or after the entity manager was cleared): a
 * Project loaded before a template was saved still holds the old template list.
 */
final readonly class ProjectSnapshot
{
    private const string TIME = 'Y-m-d\TH:i:s\Z';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function fromProject(Project $project): self
    {
        $utc = new \DateTimeZone('UTC');

        return new self([
            'name' => $project->name(),
            'git' => $project->git(),
            'default_branch' => $project->defaultBranch(),
            'default_template' => $project->defaultTemplate(),
            'additional_hostnames' => $project->additionalHostnames(),
            'ports' => array_map(static fn (NamedPort $port): string => $port->name(), $project->ports()),
            'git_bot' => [
                'mode' => $project->gitBotMode()->value,
                'name' => $project->gitBotName(),
                'email' => $project->gitBotEmail(),
            ],
            'issue_id_regexp' => $project->issueIdRegexp(),
            'display_submodule_branch' => $project->displaySubmoduleBranch(),
            'typesense' => $project->typesense(),
            'templates' => array_map(static fn (Template $template): array => [
                'name' => $template->name(),
                'drupal_env' => $template->drupalEnv(),
                'post_deploy' => [
                    'exec' => $template->postDeploy(),
                    'tty1' => $template->tty1(),
                    'tty2' => $template->tty2(),
                ],
            ], $project->templates()),
            'created_at' => $project->createdAt()->setTimezone($utc)->format(self::TIME),
            'updated_at' => $project->updatedAt()->setTimezone($utc)->format(self::TIME),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
