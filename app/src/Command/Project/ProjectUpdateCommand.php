<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ProjectSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:update', description: 'Change a project (only the options you give)')]
final class ProjectUpdateCommand extends Command
{
    public function __construct(private readonly ProjectService $projects, private readonly InstanceSiteRefresher $sites)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project name');
        ProjectOptions::configure($this, true);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            $current = ProjectInput::fromProject($this->projects->get($name));
            $project = $this->projects->update($name, $current->with(ProjectOptions::changes($input, true, $current->gitBotMode)));
            $out->emit($out->withWarning(ProjectSnapshot::fromProject($project)->toArray(), $this->sites->takeWarning()), static function (JsonOutput $out) use ($project): void {
                $out->success(\sprintf('Project "%s" updated.', $project->name()));
            });
        });
    }
}
