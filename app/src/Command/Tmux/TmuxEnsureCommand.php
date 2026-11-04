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
use Symfony\Component\Lock\LockFactory;

/**
 * Reconciles the `fleet` tmux session with the instances: the `general` window (a window still named `host` is renamed), a
 * window for every instance whose checkout is on disk, windows healed in place (a missing sidebar or second shell is added,
 * nothing is killed), managed windows of instances that are gone or going away pruned. An instance with a deploy or destroy
 * in progress (its lock is held) is skipped. Run by fleet-tmux.service at boot and by `fleet tmux` on every attach; safe to
 * run again at any time.
 */
#[AsCommand(name: 'fleet:tmux:ensure', description: 'Create or heal the fleet tmux session: general + one window per instance, prune the gone ones (idempotent)')]
final class TmuxEnsureCommand extends Command
{
    public function __construct(
        private readonly Tmux $tmux,
        private readonly InstanceRepository $instances,
        private readonly FleetPaths $paths,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->tmux->ensureSession();
            $this->tmux->applySettings();
            $this->tmux->ensureGeneral();
            $created = 0;
            foreach ($this->instances->all() as $instance) {
                $id = $instance->id();
                $dir = $this->paths->instanceDir($id);
                if (InstanceState::Destroying === $instance->state() || !is_dir($dir)) {
                    continue;
                }
                $lock = $this->locks->createLock('instance.'.$id);
                if (!$lock->acquire(false)) {
                    continue;
                }
                try {
                    if ($this->tmux->ensureWindow($id, $dir)) {
                        ++$created;
                    }
                } finally {
                    $lock->release();
                }
            }
            $pruned = 0;
            foreach ($this->tmux->managedWindows() as $window) {
                if (Tmux::HOME_WINDOW === $window || 1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $window)) {
                    continue;
                }
                $instance = $this->instances->find($window);
                if (null !== $instance && InstanceState::Destroying !== $instance->state()) {
                    continue;
                }
                // A destroy in progress holds the lock and closes the window itself.
                $lock = $this->locks->createLock('instance.'.$window);
                if (!$lock->acquire(false)) {
                    continue;
                }
                try {
                    if ($this->tmux->closeWindow($window)) {
                        ++$pruned;
                    }
                } finally {
                    $lock->release();
                }
            }
        } catch (WorkspaceException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('The "%s" session is ready; %d window(s) created, %d pruned.', Tmux::SESSION, $created, $pruned));

        return Command::SUCCESS;
    }
}
