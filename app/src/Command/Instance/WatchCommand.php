<?php

declare(strict_types=1);

namespace App\Command\Instance;

use App\Fleet\Instances\InstanceWatcher;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:instance:watch', description: 'Keep the instance states and memory true to ddev and docker (the fleet-status unit)')]
final class WatchCommand extends Command implements SignalableCommandInterface
{
    private bool $stop = false;

    public function __construct(
        private readonly InstanceWatcher $watcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between two ticks', '10')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run one tick and exit');
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = $input->getOption('interval');
        if (!\is_string($interval) || 1 !== preg_match('/^[0-9]{1,4}$/D', $interval) || (int) $interval < 1) {
            $output->writeln('<error>--interval must be a number of seconds from 1 to 9999.</error>');

            return Command::INVALID;
        }

        do {
            try {
                $changed = $this->watcher->tick($this->clock->now());
                if ($output->isVerbose()) {
                    $output->writeln(\sprintf('%d instance(s) changed.', $changed));
                }
            } catch (\Throwable $e) {
                // The unit must outlive a bad tick (a locked database, a ddev hiccup).
                $this->logger->error('The instance watcher tick failed.', ['exception' => $e]);
            }

            if (true === $input->getOption('once')) {
                break;
            }
            for ($slept = 0; $slept < (int) $interval && !$this->stop; ++$slept) {
                sleep(1);
            }
        } while (!$this->stop);

        return Command::SUCCESS;
    }
}
