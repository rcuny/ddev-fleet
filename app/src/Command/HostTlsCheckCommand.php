<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Tls\TlsChecker;
use App\Fleet\Tls\TlsStatusFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Looks at Caddy's journal and certificate and writes the status file the banner, the Host page and
 * `fleet host:tls-status` read. Run every 10 minutes by fleet-tls-check.timer. The exit code says whether the check
 * ran, not whether the certificates are healthy (that is what host:tls-status reports).
 */
#[AsCommand(
    name: 'fleet:host:tls-check',
    description: 'Check Caddy\'s certificate health (rate limits, failed solves, expiry) and write the status file',
)]
final class HostTlsCheckCommand extends Command
{
    public function __construct(private readonly TlsChecker $checker, private readonly TlsStatusFile $file, private readonly HostSettingsRepository $settings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $status = $this->checker->check($this->settings->get()?->domain());
            $this->file->write($status);
        } catch (\RuntimeException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->writeln(\sprintf('Certificates: %s', $status->level->label()), OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
