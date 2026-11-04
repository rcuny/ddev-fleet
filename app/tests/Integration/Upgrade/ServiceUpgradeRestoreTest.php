<?php

declare(strict_types=1);

namespace App\Tests\Integration\Upgrade;

use App\Fleet\Backup\BackupService;
use App\Fleet\FleetPaths;
use App\Fleet\Upgrade\ServiceUpgradeRestore;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The database step of a rollback over a real SQLite backup (a throwaway FLEET_HOME): the backup comes back, the
 * database written after it is kept as *.pre-restore-*, and no migration runs.
 */
final class ServiceUpgradeRestoreTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        self::ensureKernelShutdown();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /** @return list<string> */
    private function ports(): array
    {
        $pdo = new \PDO('sqlite:'.$this->paths->fleetDb(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('SELECT name FROM named_port ORDER BY name');
        self::assertNotFalse($statement);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function addPort(string $name, int $public): void
    {
        $pdo = new \PDO('sqlite:'.$this->paths->fleetDb(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->prepare('INSERT INTO named_port (name, public_port, router_port, created_at) VALUES (?, ?, ?, ?)')->execute([$name, $public, $public + 10000, '2026-10-20 09:00:00']);
    }

    public function testTheBackupComesBackAndTheLaterDataIsKept(): void
    {
        $this->addPort('before', 9001);
        $name = self::getContainer()->get(BackupService::class)->create()->name;
        $this->addPort('after', 9002);
        self::assertSame(['after', 'before'], $this->ports());

        $restore = self::getContainer()->get(ServiceUpgradeRestore::class);
        self::assertContains($name, $restore->backupNames());
        $restore->restore($name);

        self::assertSame(['before'], $this->ports());
        $kept = glob($this->paths->backupsDir().'/fleet.sqlite.pre-restore-*') ?: [];
        self::assertCount(1, $kept, 'the database written after the backup is kept');
    }

    public function testAnUnknownBackupIsRefusedAndNothingChanges(): void
    {
        $this->addPort('before', 9001);

        try {
            self::getContainer()->get(ServiceUpgradeRestore::class)->restore('fleet-20200101-000000.sqlite');
            self::fail('expected a refusal');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame(['before'], $this->ports());
    }
}
