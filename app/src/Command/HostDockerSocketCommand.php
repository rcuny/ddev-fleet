<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Host\Docker\DockerSocketCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-298: `fleet:host:docker-socket` checks that /var/run/docker.sock is root:docker 0660 (exit 1 when it is not). With
 * `--restore` it sets mode and group back and audits it; that needs root, so run it as `sudo fleet host:docker-socket --restore`.
 */
#[AsCommand(name: 'fleet:host:docker-socket', description: 'Check (and with --restore, fix) the permissions of the Docker socket')]
final class HostDockerSocketCommand extends Command
{
    public function __construct(private readonly DockerSocketCheck $check, private readonly AuditLog $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('restore', null, InputOption::VALUE_NONE, 'Set the socket back to root:docker 0660 (needs root)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $state = $this->check->check();
        if ($state->ok) {
            $output->writeln($state->path.': '.$state->summary.' - ok');

            return Command::SUCCESS;
        }
        $output->writeln($state->path.': '.$state->summary);
        if (true !== $input->getOption('restore')) {
            return Command::FAILURE;
        }
        if (!$this->check->restore()) {
            $output->writeln('<error>Could not restore the socket (run as root).</error>');
            $this->audit->record(AuditLog::CLI_ACTOR, AuditLog::HOST_DOCKER_SOCKET, $state->summary.' - restore failed');

            return Command::FAILURE;
        }
        $this->audit->record(AuditLog::CLI_ACTOR, AuditLog::HOST_DOCKER_SOCKET, $state->summary.' - restored to root:docker 0660');
        $output->writeln('Restored to root:docker 0660.');

        return Command::SUCCESS;
    }
}
