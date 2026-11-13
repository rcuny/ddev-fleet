<?php

declare(strict_types=1);

namespace App\Command\Host;

use App\Fleet\Isolation\DockerEventsProcess;
use App\Fleet\Isolation\IsolationConverger;
use App\Fleet\Isolation\IsolationEventListener;
use App\Fleet\Shell\CommandRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FLE-327 (follow-up of FLE-299): `fleet host:isolation:watch`, the fleet-isolation-watch unit. Listens to `docker events` and
 * runs an isolation pass about a second after a ddev container or the shared / link networks change, so a router that came
 * back with a new address is let through again at once instead of at the watcher's next pass (up to two minutes). Exits (the
 * unit restarts it) when the Docker stream ends.
 */
#[AsCommand(name: 'fleet:host:isolation:watch', description: 'Re-apply the instance isolation rules within about a second of a Docker container or network event')]
final class HostIsolationWatchCommand extends Command implements SignalableCommandInterface
{
    private bool $stop = false;

    public function __construct(
        private readonly IsolationConverger $converger,
        private readonly CommandRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int
    {
        $this->stop = true;

        return Command::SUCCESS;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $listener = new IsolationEventListener(fn () => $this->converger->pass(fn (string $line) => $this->logger->warning($line)), $this->logger);
        // The stream starts at this second, before the start pass, so events during the pass are replayed. The pass catches up
        // whatever happened while the unit was down (a restart, an upgrade).
        $source = new DockerEventsProcess($this->runner, time());
        $this->converger->pass(fn (string $line) => $this->logger->warning($line));
        if ($listener->run($source, fn (): bool => $this->stop)) {
            return Command::SUCCESS;
        }
        $this->logger->error('docker events did not answer; the unit restarts the listener.');

        return Command::FAILURE;
    }
}
