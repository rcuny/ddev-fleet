<?php

declare(strict_types=1);

namespace App\Command\Ssh;

use App\Fleet\Ssh\PushKeyCheck;
use App\Fleet\Ssh\PushKeyState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether the push key is registered on a git host (FLE-271). Tests the push key alone (ignores ~/.ssh/config and any
 * agent). Exits 1 unless the host accepted it.
 */
#[AsCommand(name: 'fleet:ssh:check-push-key', description: 'Check that the host push key is registered on a git host (tests that key only)')]
final class SshCheckPushKeyCommand extends Command
{
    public function __construct(private readonly PushKeyCheck $check)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'The git host to test', PushKeyCheck::DEFAULT_HOST);
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        try {
            $state = $this->check->run($host);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::INVALID;
        }
        $message = $state->message(strtolower($host));
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['host' => strtolower($host), 'state' => $state->value, 'ok' => PushKeyState::Registered === $state, 'message' => $message], \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $output->writeln($message, OutputInterface::OUTPUT_RAW);
        }

        return PushKeyState::Registered === $state ? Command::SUCCESS : Command::FAILURE;
    }
}
