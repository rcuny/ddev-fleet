<?php

declare(strict_types=1);

namespace App\Command\Workspace;

use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only: whether the push key, the git identity and the Claude token are in place, and whether the tmux server of the
 * workspace is alive. Booleans only, never a value. Exits 1 when one is missing or the tmux server is dead.
 *
 * The tmux check stands in for supervision: fleet-tmux.service is a oneshot that cannot notice the server dying
 * (the fix is `systemctl restart fleet-tmux`, which recreates the session; the panes of a dead server are gone).
 */
#[AsCommand(name: 'fleet:workspace:check', description: 'Check that the push key, the git identity and the Claude token are set (read-only)')]
final class WorkspaceCheckCommand extends Command
{
    public function __construct(private readonly WorkspaceCheck $check, private readonly Tmux $tmux)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the checks as JSON booleans');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->check->run();
        try {
            $tmux = $this->tmux->hasSession();
        } catch (CommandRunnerException) {
            $tmux = false;
        }
        $checks = $status->toArray() + ['tmux_server' => $tmux];

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($checks, \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $labels = ['push_key' => 'Push key', 'git_identity' => 'Git identity', 'claude_token' => 'Claude token', 'tmux_server' => 'Tmux server'];
            foreach ($checks as $key => $present) {
                $output->writeln(\sprintf('%-13s %s', $labels[$key], $present ? ('tmux_server' === $key ? 'running' : 'present') : ('tmux_server' === $key ? 'DEAD' : 'MISSING')), OutputInterface::OUTPUT_RAW);
            }
        }

        return $status->ok() && $tmux ? Command::SUCCESS : Command::FAILURE;
    }
}
