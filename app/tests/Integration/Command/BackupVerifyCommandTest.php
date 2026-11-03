<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Fleet\Backup\BackupService;
use App\Fleet\FleetPaths;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupVerifyCommandTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function verify(string $file, array $input = []): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('fleet:backup:verify'));
        $tester->execute(['file' => $file] + $input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function backup(): string
    {
        $info = self::getContainer()->get(BackupService::class)->create();

        return $this->paths->backupsDir().'/'.$info->name;
    }

    public function testASoundBackupPassesAndIsLeftExactlyAsItWas(): void
    {
        $file = $this->backup();
        $bytes = (string) file_get_contents($file);
        $mtime = filemtime($file);
        clearstatcache();

        $tester = $this->verify($file);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('integrity: ok', $tester->getDisplay());
        self::assertStringContainsString('schema: current', $tester->getDisplay());
        clearstatcache();
        self::assertSame($bytes, (string) file_get_contents($file), 'A read-only check does not write the file.');
        self::assertSame($mtime, filemtime($file));
        self::assertSame([$file], glob($this->paths->backupsDir().'/fleet-*') ?: [], 'No journal or copy is left next to it.');
    }

    public function testTheJsonResult(): void
    {
        $tester = $this->verify($this->backup(), ['--json' => true]);

        $result = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok']);
        self::assertSame('ok', $result['integrity']);
        self::assertSame('current', $result['schema']);
        self::assertSame(0, $result['pending_migrations']);
        self::assertSame([], $result['problems']);
    }

    public function testABackupFromAnOlderReleaseSaysWhichMigrationsToRunAfterTheRestore(): void
    {
        $file = $this->backup();
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec("DELETE FROM doctrine_migration_versions WHERE version LIKE '%Version20261022100000'");
        unset($pdo);

        $tester = $this->verify($file);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('schema: behind', $tester->getDisplay());
        self::assertStringContainsString('1 migration(s) are newer than this file', $tester->getDisplay());
    }

    public function testABackupFromANewerReleaseFails(): void
    {
        $file = $this->backup();
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec("INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\\Version20991231000000', '2099-12-31 00:00:00', 1)");
        unset($pdo);

        $tester = $this->verify($file);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('newer release', $tester->getErrorOutput());
    }

    public function testACorruptFileFails(): void
    {
        $file = $this->backup();
        $bytes = (string) file_get_contents($file);
        // Keep the header, ruin a page in the middle.
        file_put_contents($file, substr($bytes, 0, 8192).str_repeat("\xFF", 4096).substr($bytes, 12288));

        $tester = $this->verify($file);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('do not restore', $tester->getErrorOutput());
    }

    public function testNotADatabaseFails(): void
    {
        $file = $this->paths->backupsDir().'/fleet-20990101-000000.sqlite';
        file_put_contents($file, "just text\n");

        $tester = $this->verify($file, ['--json' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not an SQLite database', $tester->getDisplay());
    }

    public function testAMissingFileAndTheLiveDatabaseAreRefusedWithExitTwo(): void
    {
        self::assertSame(2, $this->verify('/nonexistent/backup.sqlite')->getStatusCode());

        $live = $this->verify($this->paths->fleetDb());
        self::assertSame(2, $live->getStatusCode());
        self::assertStringContainsString('live database', $live->getErrorOutput());
    }
}
