<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Backup\BackupRestorer;
use App\Fleet\Backup\BackupService;
use App\Fleet\Backup\RestoreFailed;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Always on a throwaway FLEET_HOME (FleetDatabase), never the shared dev database.
 */
final class BackupRestoreCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private const string SEED_MIGRATION = 'DoctrineMigrations\Version20261023100000';

    private FleetPaths $paths;
    private RecordingCommandRunner $runner;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        // A new process, as `fleet backup:restore` is: the migrations the set-up ran in this one are frozen objects.
        self::ensureKernelShutdown();
        self::bootKernel();
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);
        $this->runner = $runner;
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function backup(): string
    {
        $info = self::getContainer()->get(BackupService::class)->create();

        return $this->paths->backupsDir().'/'.$info->name;
    }

    private function addPort(string $name, int $public): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'INSERT INTO named_port (name, public_port, router_port, created_at) VALUES (?, ?, ?, ?)',
            [$name, $public, $public + 10000, '2026-10-20 09:00:00'],
        );
    }

    /**
     * @return list<string>
     */
    private function ports(?string $database = null): array
    {
        $pdo = new \PDO('sqlite:'.($database ?? $this->paths->fleetDb()), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('SELECT name FROM named_port ORDER BY name');
        self::assertNotFalse($statement);
        $names = array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
        unset($pdo, $statement);

        return array_values($names);
    }

    /**
     * @return list<string>
     */
    private function backupDirListing(): array
    {
        return array_values(array_diff(scandir($this->paths->backupsDir()) ?: [], ['.', '..']));
    }

    private static function marker(string $database): string
    {
        $statement = new \PDO('sqlite:'.$database)->query('SELECT v FROM marker');
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    private function liveSnapshot(): string
    {
        return (string) hash_file('sha256', $this->paths->fleetDb());
    }

    public function testRoundTripBringsTheOldDataBackKeepsThePreviousDatabaseAndMigrates(): void
    {
        $this->addPort('alpha', 9001);
        $file = $this->backup();
        // Make the backup look like an older release: the seed migration has not run in it.
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec("DELETE FROM doctrine_migration_versions WHERE version = '".self::SEED_MIGRATION."'");
        unset($pdo);
        $this->addPort('beta', 9002);
        self::assertSame(['alpha', 'beta'], $this->ports());

        $tester = $this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true, '--json' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay().$tester->getErrorOutput());
        $result = $this->json($tester);
        self::assertTrue($result['restored']);
        self::assertSame(1, $result['migrations_run']);
        self::assertSame('valid', $result['schema']);
        self::assertSame(['fleet', 'queue'], array_keys($result['previous']));
        self::assertNull($result['queue_file']);
        self::assertNull($result['previous']['queue'], 'no queue database existed, so none was kept');

        // The old data is back, and the migration seeded the catalogue port the backup lacked.
        self::assertSame(['alpha', 'typesense'], $this->ports());
        // The previous database holds what was changed after the backup.
        $previous = $result['previous']['fleet'];
        self::assertStringStartsWith($this->paths->backupsDir().'/fleet.sqlite.pre-restore-', $previous);
        self::assertSame(['alpha', 'beta'], $this->ports($previous));
        self::assertSame(0o600, fileperms($previous) & 0o777);
        self::assertSame([], array_filter($this->backupDirListing(), static fn (string $n): bool => str_starts_with($n, '.')), 'no temp file is left in backups');
        self::assertSame([], glob($this->paths->varDir().'/.restore-*') ?: [], 'no temp file is left next to the database');
    }

    public function testTheQueueSiblingWithTheSameStampIsRestoredToo(): void
    {
        $queue = new \PDO('sqlite:'.$this->paths->queueDb());
        $queue->exec('CREATE TABLE marker (v TEXT)');
        $queue->exec("INSERT INTO marker VALUES ('before')");
        unset($queue);
        $file = $this->backup();
        $queue = new \PDO('sqlite:'.$this->paths->queueDb());
        $queue->exec("UPDATE marker SET v = 'after'");
        unset($queue);

        $tester = $this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true, '--json' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay().$tester->getErrorOutput());
        $result = $this->json($tester);
        self::assertNotNull($result['queue_file']);
        $read = static fn (string $db): string => self::marker($db);
        self::assertSame('before', $read($this->paths->queueDb()));
        self::assertSame('after', $read($result['previous']['queue']));
    }

    public function testAKeptDatabaseCanBeRestoredAgainToUndoARestore(): void
    {
        $this->addPort('alpha', 9001);
        $file = $this->backup();
        $this->addPort('beta', 9002);
        $first = $this->json($this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true, '--json' => true]));
        self::assertSame(['alpha'], array_values(array_diff($this->ports(), ['typesense'])));
        sleep(1); // the kept name carries a one-second stamp

        $undo = $this->cli('fleet:backup:restore', ['file' => $first['previous']['fleet'], '--yes' => true, '--json' => true]);

        self::assertSame(0, $undo->getStatusCode(), $undo->getDisplay().$undo->getErrorOutput());
        self::assertSame(['alpha', 'beta'], array_values(array_diff($this->ports(), ['typesense'])));
    }

    public function testItRefusesAMissingFileAndAFileThatIsNotAFleetBackupWithExitTwo(): void
    {
        self::assertSame(2, $this->cli('fleet:backup:restore', ['file' => '/nonexistent/fleet-20260101-000000.sqlite', '--yes' => true])->getStatusCode());

        $other = $this->paths->backupsDir().'/queue-20990101-000000.sqlite';
        file_put_contents($other, 'x');
        $tester = $this->cli('fleet:backup:restore', ['file' => $other, '--yes' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('fleet database backup', $tester->getErrorOutput());

        $live = $this->cli('fleet:backup:restore', ['file' => $this->paths->fleetDb(), '--yes' => true]);
        self::assertSame(2, $live->getStatusCode(), 'the live database is not a backup');
    }

    public function testItRefusesACorruptFileAndTouchesNothing(): void
    {
        $file = $this->backup();
        $bytes = (string) file_get_contents($file);
        file_put_contents($file, substr($bytes, 0, 8192).str_repeat("\xFF", 4096).substr($bytes, 12288));
        $before = $this->liveSnapshot();
        $listing = $this->backupDirListing();

        $tester = $this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('nothing was changed', $tester->getErrorOutput());
        self::assertSame($before, $this->liveSnapshot());
        self::assertSame($listing, $this->backupDirListing());
    }

    public function testItRefusesABackupFromANewerRelease(): void
    {
        $file = $this->backup();
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec("INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\\Version20991231000000', '2099-12-31 00:00:00', 1)");
        unset($pdo);
        $before = $this->liveSnapshot();

        $tester = $this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('newer release', $tester->getErrorOutput());
        self::assertSame($before, $this->liveSnapshot());
    }

    public function testItRefusesWhileAJobIsQueuedOrRunning(): void
    {
        $file = $this->backup();
        $jobs = self::getContainer()->get(JobRepository::class);
        self::assertInstanceOf(JobRepository::class, $jobs);
        $jobs->add(new Job('deploy', 'acme--main', 'acme', JobTrigger::Cli, 'cli'));
        $before = $this->liveSnapshot();

        $tester = $this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true, '--json' => true]);

        self::assertSame(1, $tester->getStatusCode());
        $result = $this->json($tester);
        self::assertFalse($result['restored']);
        self::assertStringContainsString('1 job(s) are queued or running', $result['problems'][0]);
        self::assertSame($before, $this->liveSnapshot());
    }

    public function testItRefusesWhileAWorkerOrSchedulerUnitIsActiveAndPrintsTheStopLine(): void
    {
        $file = $this->backup();
        $this->runner->expect(['systemctl', 'list-units'], new CommandResult(0, "fleet-worker@1.service loaded active running Fleet worker 1\nfleet-scheduler.service loaded active running Fleet scheduler\n"));
        $before = $this->liveSnapshot();

        $tester = $this->cli('fleet:backup:restore', ['file' => $file, '--yes' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Still running: fleet-scheduler.service, fleet-worker@1.service', $tester->getErrorOutput());
        self::assertStringContainsString('sudo systemctl stop fleet-scheduler fleet-worker@1', $tester->getErrorOutput());
        self::assertSame($before, $this->liveSnapshot());
        $calls = $this->runner->callsMatching(['systemctl']);
        self::assertCount(1, $calls);
        self::assertSame('systemctl', $calls[0]->argv[0], 'a fixed argv, no shell');
    }

    public function testWithoutATerminalItNeedsYesAndChangesNothing(): void
    {
        $file = $this->backup();
        $before = $this->liveSnapshot();

        $tester = $this->cli('fleet:backup:restore', ['file' => $file]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('--yes', $tester->getErrorOutput());
        self::assertSame($before, $this->liveSnapshot());
    }

    public function testAnInteractiveAnswerOfNoCancelsAndYesRestores(): void
    {
        $this->addPort('alpha', 9001);
        $file = $this->backup();
        $this->addPort('beta', 9002);

        $no = $this->cli('fleet:backup:restore', ['file' => $file], ['no'], interactive: true);
        self::assertSame(1, $no->getStatusCode());
        self::assertSame(['alpha', 'beta'], $this->ports());

        $yes = $this->cli('fleet:backup:restore', ['file' => $file], ['yes'], interactive: true);
        self::assertSame(0, $yes->getStatusCode(), $yes->getErrorOutput());
        self::assertStringContainsString('restored: '.basename($file), $yes->getDisplay());
        self::assertStringContainsString('previous kept: ', $yes->getDisplay());
        self::assertStringContainsString('sudo systemctl start fleet-worker@1 fleet-scheduler', $yes->getErrorOutput());
        self::assertSame(['alpha'], $this->ports());
    }

    public function testAFailedMigrationPutsThePreviousDatabasesBack(): void
    {
        $queue = new \PDO('sqlite:'.$this->paths->queueDb());
        $queue->exec('CREATE TABLE marker (v TEXT)');
        unset($queue);
        $this->addPort('alpha', 9001);
        $file = $this->backup();
        $this->addPort('beta', 9002);
        $queueDb = new \PDO('sqlite:'.$this->paths->queueDb());
        $queueDb->exec("INSERT INTO marker VALUES ('live')");
        unset($queueDb);

        $restorer = self::getContainer()->get(BackupRestorer::class);
        self::assertInstanceOf(BackupRestorer::class, $restorer);
        $plan = $restorer->preflight($file);
        self::assertTrue($plan->ok(), implode('; ', $plan->refusals));
        $seen = [];
        try {
            $restorer->restore($plan, function () use (&$seen): void {
                $seen = $this->ports();
                throw new \RuntimeException('boom');
            });
            self::fail('The restore should have failed.');
        } catch (RestoreFailed $e) {
            self::assertTrue($e->rolledBack);
            self::assertStringContainsString('previous database is live again', $e->getMessage());
        }

        self::assertSame(['alpha'], $seen, 'the migrate step ran against the restored database');
        self::assertSame(['alpha', 'beta'], $this->ports());
        self::assertSame('live', self::marker($this->paths->queueDb()), 'the queue database is back too');
        self::assertSame([], glob($this->paths->varDir().'/.restore-*') ?: [], 'no temp file is left next to the databases');
        self::assertSame(0o600, fileperms($this->paths->fleetDb()) & 0o777);
        $kept = array_filter($this->backupDirListing(), static fn (string $n): bool => str_contains($n, '.pre-restore-'));
        self::assertCount(2, $kept, 'the previous databases are still kept after a rollback');
    }

    public function testAnExistingKeptNameStopsTheRestoreBeforeAnythingChanges(): void
    {
        $file = $this->backup();
        $restorer = self::getContainer()->get(BackupRestorer::class);
        self::assertInstanceOf(BackupRestorer::class, $restorer);
        $plan = $restorer->preflight($file);
        $stamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $before = $this->liveSnapshot();
        // Occupy this second and the next: whichever stamp the clock takes collides.
        foreach ([0, 1] as $offset) {
            touch($this->paths->backupsDir().'/fleet.sqlite.pre-restore-'.$stamp->modify('+'.$offset.' second')->format('Ymd-His'));
        }

        try {
            $restorer->restore($plan, static function (): void {
            });
            self::fail('The restore should have been refused.');
        } catch (RestoreFailed $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        self::assertSame($before, $this->liveSnapshot());
    }
}
