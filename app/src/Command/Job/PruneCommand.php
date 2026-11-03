<?php

declare(strict_types=1);

namespace App\Command\Job;

use App\Fleet\Jobs\JobPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'fleet:job:prune', description: 'Delete finished jobs and their logs past the retention')]
final class PruneCommand extends Command
{
    public const int MAX_DAYS = 3650;

    public function __construct(
        private readonly JobPruner $pruner,
        #[Autowire('%fleet.job_retention_days%')]
        private readonly int $defaultDays = 90,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep jobs finished within this many days (default FLEET_JOB_RETENTION_DAYS, 90)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('days');
        if (null === $option) {
            $days = $this->defaultDays;
        } elseif (\is_string($option) && 1 === preg_match('/^[0-9]{1,4}$/D', $option)) {
            $days = (int) $option;
        } else {
            $days = 0;
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            $output->writeln(\sprintf('<error>The retention must be from 1 to %d days.</error>', self::MAX_DAYS));

            return Command::INVALID;
        }

        $output->writeln((string) $this->pruner->prune($days));

        return Command::SUCCESS;
    }
}
