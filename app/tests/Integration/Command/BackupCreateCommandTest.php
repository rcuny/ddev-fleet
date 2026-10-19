<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\BackupCreateCommand;
use App\Domain\Host\HostSettingsRepository;
use App\Fleet\Backup\BackupService;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupCreateCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private HostSettingsRepository $settings;
    private MockClock $clock;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->settings = new HostSettingsRepository($em);
        $this->clock = new MockClock('2026-10-19 08:30:15 UTC');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function run_(): CommandTester
    {
        $tester = new CommandTester(new BackupCreateCommand(new BackupService($this->paths, $this->clock), $this->settings));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testPrintsTheFileNameAndWritesAReadableBackupOfTheRealDatabase(): void
    {
        $this->settings->getOrCreate('fleet.example.com');
        $queue = new \PDO('sqlite:'.$this->paths->queueDb());
        $queue->exec('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY, body TEXT)');
        unset($queue);

        $tester = $this->run_();

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertSame("fleet-20261019-083015.sqlite\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        $file = $this->paths->backupsDir().'/fleet-20261019-083015.sqlite';
        clearstatcache();
        self::assertSame(0o600, fileperms($file) & 0o777);
        $copy = new \PDO('sqlite:'.$file);
        $statement = $copy->query('SELECT domain FROM host_settings');
        self::assertNotFalse($statement);
        self::assertSame('fleet.example.com', $statement->fetchColumn());
        self::assertFileExists($this->paths->backupsDir().'/queue-20261019-083015.sqlite');
    }

    public function testPrunesWithTheStoredRetention(): void
    {
        $host = $this->settings->getOrCreate('fleet.example.com');
        $host->changeBackupRetentionDays(3);
        $this->settings->save($host);
        $dir = $this->paths->backupsDir();
        $now = $this->clock->now()->getTimestamp();
        touch($dir.'/fleet-20261001-000000.sqlite', $now - 5 * 86400);
        touch($dir.'/fleet-20261018-000000.sqlite', $now - 86400);

        $tester = $this->run_();

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertFileDoesNotExist($dir.'/fleet-20261001-000000.sqlite');
        self::assertFileExists($dir.'/fleet-20261018-000000.sqlite');
        self::assertFileExists($dir.'/fleet-20261019-083015.sqlite');
        self::assertStringContainsString('Pruned 1 backup file(s) older than 3 days', $tester->getErrorOutput());
        self::assertSame("fleet-20261019-083015.sqlite\n", $tester->getDisplay(), 'stdout stays one line');
    }

    public function testUsesTheDefaultRetentionBeforeTheHostIsInitialised(): void
    {
        $tester = $this->run_();

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    public function testFailsWithANonZeroExitAndNothingOnStdout(): void
    {
        unlink($this->paths->fleetDb());

        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Backup failed', $tester->getErrorOutput());
        self::assertSame([], glob($this->paths->backupsDir().'/*'));
    }
}
