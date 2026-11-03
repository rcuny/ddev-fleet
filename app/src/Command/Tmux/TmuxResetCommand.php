<?php

declare(strict_types=1);

namespace App\Command\Tmux;

use App\Domain\Instance\InstanceRepository;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds a window in place (fresh shells and sidebar, the tab keeps its index); without an argument, every instance
 * window (the shells in them are lost, the instances are not touched). `--pane %N` (what `^b R` passes) resets the window
 * of that pane, its name read back from tmux.
 */
#[AsCommand(name: 'fleet:tmux:reset', description: 'Rebuild one window (or all of them) in place with fresh shells and sidebar')]
final class TmuxResetCommand extends Command
{
    public function __construct(private readonly Tmux $tmux, private readonly InstanceRepository $instances)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('window', InputArgument::OPTIONAL, 'Instance id of the window, or general; omit it to reset every instance window');
        $this->addOption('pane', null, InputOption::VALUE_REQUIRED, 'Reset the window this tmux pane id (%12) is in');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $window = $input->getArgument('window');
        $pane = $input->getOption('pane');
        if (\is_string($pane)) {
            $window = $this->tmux->windowOfPane($pane);
            if (null === $window) {
                $io->error('That pane has no window.');

                return Command::FAILURE;
            }
        }

        try {
            if (\is_string($window)) {
                if (Tmux::HOME_WINDOW !== $window && null === $this->instances->find($window)) {
                    $io->error(\sprintf('There is no instance "%s".', $window));

                    return Command::FAILURE;
                }
                $ids = [$window];
            } else {
                $ids = $this->instances->ids();
            }
            foreach ($ids as $id) {
                $this->tmux->reset($id);
            }
        } catch (WorkspaceException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d window(s) reset.', \count($ids)));

        return Command::SUCCESS;
    }
}
