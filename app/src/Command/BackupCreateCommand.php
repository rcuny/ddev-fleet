<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Backup\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backs the databases up and prunes old backups. Run daily by fleet-backup.timer. stdout is the one backup file
 * name; anything else goes to stderr.
 */
#[AsCommand(
    name: 'fleet:backup:create',
    description: 'Back up the databases to FLEET_HOME/backups and prune backups older than the retention',
)]
final class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly HostSettingsRepository $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = new SymfonyStyle($input, $output)->getErrorStyle();

        try {
            $backup = $this->backups->create();
            $retention = $this->settings->get()?->backupRetentionDays() ?? HostSettings::DEFAULT_BACKUP_RETENTION_DAYS;
            $pruned = $this->backups->prune($retention);
        } catch (\Throwable $e) {
            $errors->error('Backup failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $pruned) {
            $errors->writeln(\sprintf('Pruned %d backup file(s) older than %d days.', \count($pruned), $retention));
        }
        $output->writeln($backup->name, OutputInterface::OUTPUT_PLAIN | OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }
}
