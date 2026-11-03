<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Project\Project;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ProjectSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:list', description: 'List the projects')]
final class ProjectListCommand extends Command
{
    public function __construct(private readonly ProjectService $projects)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());

        return $out->run(function () use ($out): void {
            $projects = $this->projects->list();
            $out->emit(
                array_map(static fn (Project $project): array => ProjectSnapshot::fromProject($project)->toArray(), $projects),
                static fn (JsonOutput $out) => $out->table(
                    ['NAME', 'GIT', 'DEFAULT BRANCH', 'TEMPLATES'],
                    array_map(static fn (Project $project): array => [$project->name(), $project->git(), $project->defaultBranch() ?? '-', \count($project->templates())], $projects),
                ),
            );
        });
    }
}
