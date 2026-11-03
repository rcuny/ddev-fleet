<?php

declare(strict_types=1);

namespace App\Command\Tmux;

use App\Fleet\Workspace\SidebarRenderer;
use App\Fleet\Workspace\WorkspaceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The program of the sidebar pane of an instance window: prints the instance's state, URL and branches, and again every
 * few seconds until the pane is closed. `--once` prints once and exits.
 */
#[AsCommand(name: 'fleet:tmux:sidebar', description: 'Print the sidebar of an instance window (state, URL, branches); repeats unless --once')]
final class TmuxSidebarCommand extends Command
{
    public const int REFRESH_SECONDS = 5;

    public function __construct(private readonly SidebarRenderer $sidebar)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('window', null, InputOption::VALUE_REQUIRED, 'Instance id of the window');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Print once and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = $input->getOption('window');
        if (!\is_string($window) || '' === $window) {
            new SymfonyStyle($input, $output)->error('Give the instance id with --window.');

            return Command::INVALID;
        }
        $once = true === $input->getOption('once');

        do {
            try {
                $text = $this->sidebar->render($window);
            } catch (WorkspaceException $e) {
                new SymfonyStyle($input, $output)->error($e->getMessage());

                return Command::INVALID;
            }
            if (!$once) {
                $output->write("\e[H\e[2J", false, OutputInterface::OUTPUT_RAW);
            }
            $output->write($text, false, OutputInterface::OUTPUT_RAW);
            if (!$once) {
                sleep(self::REFRESH_SECONDS);
            }
        } while (!$once);

        return Command::SUCCESS;
    }
}
