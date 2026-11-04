<?php

declare(strict_types=1);

namespace App\Command\Upgrade;

use App\Fleet\Upgrade\UpgradeRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal: the database step of a rollback (`sudo fleet upgrade --to <older>`). The root helper stops the services,
 * runs this as the fleet user, and installs the older release afterwards (its migrations run with the older code).
 * Exit 0 restored, 1 refused or failed (the previous databases are back, nothing else changed).
 */
#[AsCommand(
    name: 'fleet:upgrade:restore',
    description: 'Internal: restore the pre-upgrade backup for a rollback',
    hidden: true,
)]
final class UpgradeRestoreCommand extends Command
{
    public function __construct(private readonly UpgradeRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('backup', null, InputOption::VALUE_REQUIRED, 'The backup file name (fleet-YYYYmmdd-HHMMSS.sqlite)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('backup');
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (!\is_string($name) || 1 !== preg_match('/^fleet-\d{8}-\d{6}\.sqlite$/D', $name)) {
            $err->writeln('upgrade: --backup must name a fleet backup file (fleet-YYYYmmdd-HHMMSS.sqlite)', OutputInterface::OUTPUT_RAW);

            return 2;
        }
        try {
            $this->runner->restore($name);
        } catch (\Throwable $e) {
            $err->writeln('upgrade: the database restore failed, the previous database is in place: '.preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $e->getMessage()), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
        $output->writeln('RESTORED='.$name, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
