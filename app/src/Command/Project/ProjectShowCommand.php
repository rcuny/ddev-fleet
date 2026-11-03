<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Port\NamedPort;
use App\Domain\Template\Template;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ProjectSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:show', description: 'Show one project')]
final class ProjectShowCommand extends Command
{
    public function __construct(private readonly ProjectService $projects)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project name');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());

        return $out->run(function () use ($input, $out): void {
            $project = $this->projects->get((string) $input->getArgument('name'));
            $out->emit(ProjectSnapshot::fromProject($project)->toArray(), static function (JsonOutput $out) use ($project): void {
                $out->pairs([
                    'name' => $project->name(),
                    'git' => $project->git(),
                    'default branch' => $project->defaultBranch(),
                    'default template' => $project->defaultTemplate(),
                    'additional hostnames' => $project->additionalHostnames(),
                    'ports' => array_map(static fn (NamedPort $port): string => $port->name(), $project->ports()),
                    'git bot' => $project->gitBotMode()->value,
                    'git bot name' => $project->gitBotName(),
                    'git bot email' => $project->gitBotEmail(),
                    'issue id regexp' => $project->issueIdRegexp(),
                    'display submodule branch' => $project->displaySubmoduleBranch(),
                    'typesense' => $project->typesense() ? 'yes' : 'no',
                    'templates' => array_map(static fn (Template $template): string => $template->name(), $project->templates()),
                    'created' => $project->createdAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC',
                    'updated' => $project->updatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC',
                ]);
            });
        });
    }
}
