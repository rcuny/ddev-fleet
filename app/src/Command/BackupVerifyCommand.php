<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\Backup\BackupVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks a backup file before a restore: SQLite's integrity check and the schema version, on a read-only open. It never
 * writes, and it refuses the live database. Exit 0 the file is sound, 1 it is not, 2 the path is not a file to check.
 */
#[AsCommand(
    name: 'fleet:backup:verify',
    description: 'Check a database backup file (integrity and schema version) without changing anything',
)]
final class BackupVerifyCommand extends Command
{
    public function __construct(private readonly BackupVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The backup file, for example /srv/fleet/backups/fleet-20261019-083015.sqlite');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->verifier->verify((string) $input->getArgument('file'));
        } catch (\InvalidArgumentException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return 2;
        }

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode([
                'file' => $result->path,
                'ok' => $result->ok(),
                'integrity' => $result->integrityOk ? 'ok' : 'failed',
                'schema' => $result->schema,
                'pending_migrations' => \count($result->pending),
                'problems' => $result->problems,
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $result->ok() ? Command::SUCCESS : Command::FAILURE;
        }

        foreach ($result->problems as $problem) {
            $io->getErrorStyle()->writeln($problem, OutputInterface::OUTPUT_RAW);
        }
        if (!$result->ok()) {
            $io->getErrorStyle()->error('The backup failed its check; do not restore it.');

            return Command::FAILURE;
        }
        $output->writeln('integrity: ok', OutputInterface::OUTPUT_RAW);
        $output->writeln('schema: '.($result->schema ?? 'not applicable'), OutputInterface::OUTPUT_RAW);
        if ([] !== $result->pending) {
            $output->writeln(\sprintf('After a restore run "fleet doctrine:migrations:migrate -n": %d migration(s) are newer than this file.', \count($result->pending)), OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
