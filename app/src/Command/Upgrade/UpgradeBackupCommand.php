<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\UpgradeRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Internal: the pre-upgrade backup of `sudo fleet upgrade`. Prints `BACKUP=<file name>`; exit 1 when it cannot be taken. */
#[AsCommand(
    name: 'fleet:upgrade:backup',
    description: 'Internal: take the pre-upgrade database backup',
    hidden: true,
)]
final class UpgradeBackupCommand extends Command
{
    public function __construct(private readonly UpgradeRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $name = $this->runner->backup();
        } catch (\Throwable $e) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)
                ->writeln('upgrade: the pre-upgrade backup failed, nothing was changed: '.preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $e->getMessage()), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
        $output->writeln('BACKUP='.$name, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
