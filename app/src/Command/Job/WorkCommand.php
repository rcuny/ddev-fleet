<?php

declare(strict_types=1);

namespace App\Command\Job;

use App\Fleet\Jobs\JobExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal (FLE-209): the child process the worker starts for one job when the host runs jobs concurrently. It does what
 * the worker does for a RunJob message (take the instance lock, claim, execute, record the status). Not meant for people:
 * use `fleet:instance:*` or `fleet:job:retry`.
 */
#[AsCommand(name: 'fleet:job:work', description: 'Run one queued job in this process (used by the worker)', hidden: true)]
final class WorkCommand extends Command
{
    public function __construct(private readonly JobExecutor $executor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Job number')
            ->addOption('eligible-since', null, InputOption::VALUE_REQUIRED, 'Give-up clock a previous delivery computed (Unix timestamp)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        if (1 !== preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
            $output->writeln('<error>Not a job number.</error>');

            return Command::INVALID;
        }
        $since = $input->getOption('eligible-since');
        try {
            $this->executor->run((int) $id, \is_string($since) && ctype_digit($since) ? (int) $since : null);
        } catch (\Throwable $e) {
            // Non-zero exit: the worker then retries a job that is still queued instead of waiting for the reaper.
            $output->writeln('<error>'.$e::class.': '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
