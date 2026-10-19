<?php

declare(strict_types=1);

namespace App\Command\Tmux;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\FleetPaths;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes sure the `fleet` tmux session exists with a window for every instance whose checkout is on disk. Run by
 * fleet-tmux.service at boot; safe to run again at any time (windows that exist are left alone).
 */
#[AsCommand(name: 'fleet:tmux:ensure', description: 'Create the fleet tmux session and one window per instance (idempotent)')]
final class TmuxEnsureCommand extends Command
{
    public function __construct(
        private readonly Tmux $tmux,
        private readonly InstanceRepository $instances,
        private readonly FleetPaths $paths,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->tmux->ensureSession();
            $created = 0;
            foreach ($this->instances->all() as $instance) {
                $dir = $this->paths->instanceDir($instance->id());
                if (InstanceState::Destroying === $instance->state() || !is_dir($dir)) {
                    continue;
                }
                if ($this->tmux->ensureWindow($instance->id(), $dir)) {
                    ++$created;
                }
            }
        } catch (WorkspaceException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('The "%s" session is ready; %d window(s) created.', Tmux::SESSION, $created));

        return Command::SUCCESS;
    }
}
