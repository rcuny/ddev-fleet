<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\Tls\TlsLevel;
use App\Fleet\Tls\TlsStatus;
use App\Fleet\Tls\TlsStatusFile;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the last certificate health check (fleet:host:tls-check, every 10 minutes). Exit code: 0 ok, 1 warning,
 * 2 error. No status file yet, or one older than an hour (the timer is not running), counts as a warning.
 */
#[AsCommand(
    name: 'fleet:host:tls-status',
    description: 'Show the certificate health of this host (exit 0 ok, 1 warning, 2 error)',
)]
final class HostTlsStatusCommand extends Command
{
    private const int STALE_SECONDS = 3600;

    public function __construct(private readonly TlsStatusFile $file, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->file->read();
        $json = (bool) $input->getOption('json');
        if (null === $status) {
            $json ? $output->writeln('{"level":"warning","headlines":["No certificate check has run yet."]}') : $output->writeln('warning: no certificate check has run yet (fleet-tls-check.timer writes the status every 10 minutes)');

            return TlsLevel::Warning->value;
        }
        $level = $status->level;
        $stale = $this->clock->now()->getTimestamp() - $status->checkedAt->getTimestamp() > self::STALE_SECONDS;
        if ($stale && TlsLevel::Ok === $level) {
            $level = TlsLevel::Warning;
        }

        if ($json) {
            $data = $status->toArray();
            $data['level'] = $level->label();
            $data['stale'] = $stale;
            $output->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            return $level->value;
        }

        $output->writeln(\sprintf('%s: %s (checked %s UTC)', $level->label(), [] === $status->headlines ? 'certificates look healthy' : implode(' ', $status->headlines), $status->checkedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')));
        if ($stale) {
            $output->writeln('The check is more than an hour old: is fleet-tls-check.timer running?');
        }
        foreach ($status->certificates as $cert) {
            $output->writeln(\sprintf('  %s: %s', $cert['name'], null === $cert['daysLeft'] ? 'no usable certificate' : $cert['daysLeft'].' days left'));
        }
        if ([] !== $status->affectedNames) {
            $output->writeln('  affected: '.implode(', ', $status->affectedNames));
        }
        if (null !== $status->issuedLastWeek) {
            $output->writeln(\sprintf('  issued in the last 7 days: %d of %d', $status->issuedLastWeek, TlsStatus::WEEKLY_LIMIT));
        }

        return $level->value;
    }
}
