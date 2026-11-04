<?php

declare(strict_types=1);

namespace App\Command\Tmux;

use App\Fleet\Workspace\SidebarGitCache;
use App\Fleet\Workspace\SidebarRenderer;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The program of the sidebar pane of an instance window: prints the instance list, the current one's state and URL and the key hints, and again every
 * few seconds until the pane is closed. `--once` prints once and exits.
 */
#[AsCommand(name: 'fleet:tmux:sidebar', description: 'Print the sidebar of a window (all instances, state, URL, keys); repeats unless --once')]
final class TmuxSidebarCommand extends Command
{
    public const int REFRESH_SECONDS = 5;

    public function __construct(private readonly SidebarRenderer $sidebar, private readonly Tmux $tmux)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('window', null, InputOption::VALUE_REQUIRED, 'Instance id of the window, or general (default: the window of this pane)');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Print once and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = $input->getOption('window');
        if ((!\is_string($window) || '' === $window) && \is_string($pane = getenv('TMUX_PANE'))) {
            $window = $this->tmux->windowOfPane($pane);
        }
        if (!\is_string($window) || '' === $window) {
            new SymfonyStyle($input, $output)->error('Give the instance id with --window.');

            return Command::INVALID;
        }
        $once = true === $input->getOption('once');
        $cache = new SidebarGitCache();

        do {
            try {
                $text = $this->sidebar->render($window, $cache);
            } catch (WorkspaceException $e) {
                new SymfonyStyle($input, $output)->error($e->getMessage());

                return Command::INVALID;
            }
            if (!$once) {
                $output->write("\e[H\e[2J", false, OutputInterface::OUTPUT_RAW);
            }
            $output->write($text, false, OutputInterface::OUTPUT_RAW);
            if (!$once) {
                // A resized terminal squeezes the sidebar: put the layout back (a no-op while it is 50 columns wide).
                try {
                    if ($this->tmux->layoutDrifted($window)) {
                        $this->tmux->applyLayout($window);
                    }
                } catch (WorkspaceException) {
                    // The next tick tries again.
                }
                sleep(self::REFRESH_SECONDS);
            }
        } while (!$once);

        return Command::SUCCESS;
    }
}
