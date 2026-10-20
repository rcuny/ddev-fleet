<?php

declare(strict_types=1);

namespace App\Command;

use App\Fleet\Backup\BackupRestorer;
use App\Fleet\Backup\RestoreFailed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restores a backup into the live databases: verified first, swapped atomically, the previous database kept as
 * `<name>.sqlite.pre-restore-<stamp>`, then migrated and validated (a failure puts the previous one back). The workers
 * and the scheduler must be stopped by the operator first; this command only checks they are. Exit 0 restored, 1
 * refused or failed, 2 not a usable request (missing or wrong file, no --yes without a terminal).
 */
#[AsCommand(
    name: 'fleet:backup:restore',
    description: 'Restore a database backup into the live databases (verifies first, keeps the previous database)',
)]
final class BackupRestoreCommand extends Command
{
    private const string STOP = 'sudo systemctl stop fleet-scheduler fleet-worker@1';
    private const string START = 'sudo systemctl start fleet-worker@1 fleet-scheduler';

    public function __construct(
        private readonly BackupRestorer $restorer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The fleet backup, for example /srv/fleet/backups/fleet-20261019-083015.sqlite (its queue-<same stamp>.sqlite is restored too when present)');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (required without a terminal)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();
        $json = true === $input->getOption('json');

        try {
            $plan = $this->restorer->preflight((string) $input->getArgument('file'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $errors, $json, 2, $e->getMessage(), []);
        }
        if (!$plan->ok()) {
            if (!$json && [] !== $plan->activeUnits) {
                $errors->writeln('Stop them, restore, then start them again:', OutputInterface::OUTPUT_RAW);
                $errors->writeln('  '.self::STOP, OutputInterface::OUTPUT_RAW);
            }

            return $this->fail($output, $errors, $json, 1, 'The restore was refused; nothing was changed.', $plan->refusals);
        }
        if (true !== $input->getOption('yes')) {
            if (!$input->isInteractive()) {
                return $this->fail($output, $errors, $json, 2, 'Pass --yes to restore without a terminal.', []);
            }
            $io->writeln(\sprintf('This replaces the live fleet database%s with %s. The current one is kept as *.pre-restore-*.', null === $plan->queueBackup ? '' : ' and the queue', basename($plan->fleetBackup)));
            if (!$io->confirm('Restore now?', false)) {
                return $this->fail($output, $errors, $json, 1, 'Cancelled; nothing was changed.', []);
            }
        }

        try {
            $result = $this->restorer->restore($plan, $this->migrate(...));
        } catch (RestoreFailed $e) {
            return $this->fail($output, $errors, $json, 1, $e->getMessage(), [], $e->rolledBack);
        }

        if ($json) {
            $output->writeln(json_encode([
                'restored' => true,
                'file' => $result->fleetBackup,
                'queue_file' => $result->queueBackup,
                'previous' => ['fleet' => $result->previousFleet, 'queue' => $result->previousQueue],
                'migrations_run' => \count($plan->verification->pending),
                'schema' => 'valid',
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $output->writeln('restored: '.basename($result->fleetBackup).(null === $result->queueBackup ? '' : ' and '.basename($result->queueBackup)), OutputInterface::OUTPUT_RAW);
        $output->writeln(\sprintf('migrations run: %d, schema: valid', \count($plan->verification->pending)), OutputInterface::OUTPUT_RAW);
        foreach ([$result->previousFleet, $result->previousQueue] as $previous) {
            if (null !== $previous) {
                $output->writeln('previous kept: '.$previous, OutputInterface::OUTPUT_RAW);
            }
        }
        $errors->writeln('Start the services again:', OutputInterface::OUTPUT_RAW);
        $errors->writeln('  '.self::START, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * Runs in this process, after a reconnect (the restored file replaced the one this process had open).
     */
    private function migrate(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();

        $application = $this->getApplication();
        if (null === $application) {
            throw new \LogicException('The command runs without an application.');
        }
        foreach ([['doctrine:migrations:migrate', 'Migration'], ['doctrine:schema:validate', 'Schema validation']] as [$name, $what]) {
            $buffer = new BufferedOutput();
            $arguments = ['doctrine:migrations:migrate' === $name ? '--allow-no-migration' : '--skip-sync' => true];
            $in = new ArrayInput($arguments);
            $in->setInteractive(false);
            if (0 !== $application->find($name)->run($in, $buffer)) {
                throw new \RuntimeException($what.' failed: '.mb_substr(trim($buffer->fetch()), -400));
            }
        }
        $this->em->getConnection()->close();
    }

    /**
     * @param list<string> $problems
     */
    private function fail(OutputInterface $output, OutputStyle $errors, bool $json, int $code, string $message, array $problems, ?bool $rolledBack = null): int
    {
        if ($json) {
            $output->writeln(json_encode([
                'restored' => false,
                'error' => $message,
                'problems' => $problems,
                'rolled_back' => $rolledBack,
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return $code;
        }
        foreach ($problems as $problem) {
            $errors->writeln($problem, OutputInterface::OUTPUT_RAW);
        }
        $errors->error($message);

        return $code;
    }
}
