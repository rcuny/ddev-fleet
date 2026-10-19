<?php

declare(strict_types=1);

namespace App\Command\Tmux;

use App\Fleet\Workspace\Tmux;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:tmux:list', description: 'List the windows of the fleet tmux session')]
final class TmuxListCommand extends Command
{
    public function __construct(private readonly Tmux $tmux)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print {"session": ..., "running": ..., "windows": [...]} as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $windows = $this->tmux->listWindows();

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(
                ['session' => Tmux::SESSION, 'running' => $this->tmux->hasSession(), 'windows' => $windows],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
            ), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        foreach ($windows as $window) {
            $output->writeln($window, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
