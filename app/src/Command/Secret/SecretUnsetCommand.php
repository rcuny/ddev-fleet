<?php

declare(strict_types=1);

namespace App\Command\Secret;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\Refused;
use App\Fleet\Secrets\SecretService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:secret:unset', description: 'Delete a secret')]
final class SecretUnsetCommand extends Command
{
    public function __construct(private readonly SecretService $secrets)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Secret name');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Delete a secret of this project instead of a fleet-wide one');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::secret());

        return $out->run(function () use ($input, $out): void {
            $name = (string) $input->getArgument('name');
            $project = SecretOptions::project($input);

            // A missing project or secret answers "not found" before anything is asked.
            $this->secrets->overview($project);
            if (!$out->confirmed($input, \sprintf('Delete secret "%s"?', $name))) {
                throw new Refused('Not deleted: pass --yes to confirm.');
            }
            $this->secrets->delete($project, $name, AuditLog::CLI_ACTOR);
            $out->emit(['deleted' => $name, 'project' => $project], static function (JsonOutput $out) use ($name): void {
                $out->success(\sprintf('Secret "%s" deleted.', $name));
            });
        });
    }
}
