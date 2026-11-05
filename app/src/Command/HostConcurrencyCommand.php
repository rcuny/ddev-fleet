<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows or sets how many jobs of different instances the worker runs at once (FLE-209; the Jobs tab of the host settings
 * page edits the same value).
 */
#[AsCommand(
    name: 'fleet:host:job-concurrency',
    description: 'Show or set how many jobs of different instances run at the same time',
)]
final class HostConcurrencyCommand extends Command
{
    public function __construct(private readonly HostSettingsRepository $settings, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobs', InputArgument::OPTIONAL, \sprintf('New limit, 1 to %d (1 = one job after another)', HostSettings::MAX_MAX_CONCURRENT_JOBS));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $this->settings->fresh();
        if (null === $host) {
            $output->writeln('<error>This host is not initialised yet (fleet:host:init).</error>');

            return Command::FAILURE;
        }

        $value = $input->getArgument('jobs');
        if (null !== $value) {
            $value = (string) $value;
            if (1 !== preg_match('/^[1-9][0-9]{0,2}$/D', $value) || (int) $value > HostSettings::MAX_MAX_CONCURRENT_JOBS) {
                $output->writeln(\sprintf('<error>Enter a whole number from 1 to %d.</error>', HostSettings::MAX_MAX_CONCURRENT_JOBS));

                return Command::INVALID;
            }
            $host->changeMaxConcurrentJobs((int) $value, $this->clock->now());
            $this->settings->save($host);
        }

        $output->writeln(\sprintf('Jobs at the same time: %d', $host->maxConcurrentJobs()));

        return Command::SUCCESS;
    }
}
