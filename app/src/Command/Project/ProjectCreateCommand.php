<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\ProjectSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:create', description: 'Create a project')]
final class ProjectCreateCommand extends Command
{
    public function __construct(private readonly ProjectService $projects, private readonly ProjectAccessRefresher $access)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project name (lower case letters, digits and single dashes; cannot be changed later)');
        ProjectOptions::configure($this, false);
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());

        return $out->run(function () use ($input, $out): void {
            $changes = ProjectOptions::changes($input, false);
            $project = $this->projects->create(new ProjectInput((string) $input->getArgument('name'), '')->with($changes));
            $out->emit($out->withWarning(ProjectSnapshot::fromProject($project)->toArray(), $this->access->takeWarning()), static function (JsonOutput $out) use ($project): void {
                $out->success(\sprintf('Project "%s" created.', $project->name()));
            });
        });
    }
}
