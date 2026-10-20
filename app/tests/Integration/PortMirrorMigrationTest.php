<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Host\HostSettingsRepository;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The port mirror flag (FLE-92): a host that existed before the column keeps its row and gets the mirror switched on.
 */
final class PortMirrorMigrationTest extends KernelTestCase
{
    use FleetDatabase;

    private const string MIGRATION = 'DoctrineMigrations\Version20261023090000';

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testAPopulatedDatabaseGetsTheFlagSwitchedOn(): void
    {
        $em = $this->setUpFleetDatabase();
        $connection = $em->getConnection();

        // The database as it was before this migration: no column, no version row.
        $connection->executeStatement('ALTER TABLE host_settings DROP COLUMN port_mirror');
        $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = :version', ['version' => self::MIGRATION]);
        $columns = array_map(static fn ($column): string => $column->getObjectName()->toString(), $connection->createSchemaManager()->introspectTableColumnsByUnquotedName('host_settings'));
        self::assertNotContains('port_mirror', $columns);

        $connection->executeStatement(
            'INSERT INTO host_settings (id, domain, tls_mode, created_at, updated_at) VALUES (1, :domain, :mode, :now, :now)',
            ['domain' => 'fleet.example.com', 'mode' => 'http01', 'now' => '2026-10-19 09:00:00'],
        );

        // A migration object runs once per container: boot a fresh one, as a new process would.
        self::ensureKernelShutdown();
        self::bootKernel();
        [$exit, $output] = $this->console('doctrine:migrations:migrate', ['--no-interaction' => true]);
        self::assertSame(0, $exit, $output);
        $em = $this->entityManager();
        $connection = $em->getConnection();

        self::assertSame(1, (int) $connection->fetchOne('SELECT port_mirror FROM host_settings WHERE id = 1'));
        $em->clear();
        $host = new HostSettingsRepository($em)->get();
        self::assertNotNull($host);
        self::assertSame('fleet.example.com', $host->domain());
        self::assertTrue($host->portMirror());

        [$exit, $output] = $this->console('doctrine:schema:validate', ['--skip-sync' => true]);
        self::assertSame(0, $exit, $output);
    }

    public function testTheFlagRoundTripsThroughTheEntity(): void
    {
        $em = $this->setUpFleetDatabase();
        $repository = new HostSettingsRepository($em);
        $host = $repository->getOrCreate('fleet.example.com');
        self::assertTrue($host->portMirror());

        $host->changePortMirror(false);
        $repository->save($host);
        $em->clear();

        self::assertFalse($repository->get()?->portMirror());
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT port_mirror FROM host_settings WHERE id = 1'));
    }
}
