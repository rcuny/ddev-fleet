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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Throws a window away and builds it again with fresh shells; without an argument, every instance window (the shells in
 * them are lost, the instances are not touched).
 */
#[AsCommand(name: 'fleet:tmux:reset', description: 'Rebuild one instance window (or all of them) with fresh shells')]
final class TmuxResetCommand extends Command
{
    public function __construct(private readonly Tmux $tmux, private readonly InstanceRepository $instances)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('window', InputArgument::OPTIONAL, 'Instance id of the window; omit it to reset every instance window');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $window = $input->getArgument('window');

        try {
            if (\is_string($window)) {
                if (null === $this->instances->find($window)) {
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
