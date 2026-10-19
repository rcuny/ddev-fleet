<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\Refused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:project:delete', description: 'Delete a project with its templates (refused while it has instances)')]
final class ProjectDeleteCommand extends Command
{
    public function __construct(private readonly ProjectService $projects)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Project name');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::project());

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            $this->projects->get($name);
            if (!$out->confirmed($input, \sprintf('Delete project "%s" with all its templates?', $name))) {
                throw new Refused('Not deleted: pass --yes to confirm.');
            }
            $this->projects->delete($name, AuditLog::CLI_ACTOR);
            $out->emit(['deleted' => $name], static function (JsonOutput $out) use ($name): void {
                $out->success(\sprintf('Project "%s" deleted.', $name));
            });
        });
    }
}
