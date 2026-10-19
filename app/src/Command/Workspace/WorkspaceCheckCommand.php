<?php

declare(strict_types=1);

namespace App\Command\Workspace;

use App\Fleet\Workspace\WorkspaceCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only: whether the push key, the git identity and the Claude token are in place. Booleans only, never a value.
 * Exits 1 when one is missing.
 */
#[AsCommand(name: 'fleet:workspace:check', description: 'Check that the push key, the git identity and the Claude token are set (read-only)')]
final class WorkspaceCheckCommand extends Command
{
    public function __construct(private readonly WorkspaceCheck $check)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the three checks as JSON booleans');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->check->run();

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($status->toArray(), \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $labels = ['push_key' => 'Push key', 'git_identity' => 'Git identity', 'claude_token' => 'Claude token'];
            foreach ($status->toArray() as $key => $present) {
                $output->writeln(\sprintf('%-13s %s', $labels[$key], $present ? 'present' : 'MISSING'), OutputInterface::OUTPUT_RAW);
            }
        }

        return $status->ok() ? Command::SUCCESS : Command::FAILURE;
    }
}
