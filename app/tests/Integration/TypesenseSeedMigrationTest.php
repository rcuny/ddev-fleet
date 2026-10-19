<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Port\NamedPortRepository;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue port `typesense` (public 9108, router 8108) is seeded by a migration (FLE-92), and never over an
 * entry the host already has.
 */
final class TypesenseSeedMigrationTest extends KernelTestCase
{
    use FleetDatabase;

    private const string MIGRATION = 'DoctrineMigrations\Version20261023100000';

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @return list<array{string, int, int}> name, public, router of every catalogue row after re-running the migration
     *                                       on a catalogue prepared by $prepare
     */
    private function migrateAfter(callable $prepare): array
    {
        $em = $this->setUpFleetDatabase(keepSeed: true);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM named_port');
        $prepare($connection);
        $connection->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = :version', ['version' => self::MIGRATION]);

        self::ensureKernelShutdown();
        self::bootKernel();
        [$exit, $output] = $this->console('doctrine:migrations:migrate', ['--no-interaction' => true]);
        self::assertSame(0, $exit, $output);

        $rows = [];
        foreach (self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection()->fetchAllAssociative('SELECT name, public_port, router_port FROM named_port ORDER BY name') as $row) {
            $rows[] = [(string) $row['name'], (int) $row['public_port'], (int) $row['router_port']];
        }

        return $rows;
    }

    public function testAFreshInstallHasTheTypesensePort(): void
    {
        $em = $this->setUpFleetDatabase(keepSeed: true);

        $port = new NamedPortRepository($em)->findByName('typesense');

        self::assertNotNull($port);
        self::assertSame(9108, $port->publicPort());
        self::assertSame(8108, $port->routerPort());
    }

    public function testItIsAddedToACatalogueThatLacksIt(): void
    {
        $rows = $this->migrateAfter(static function ($connection): void {
            $connection->executeStatement("INSERT INTO named_port (name, public_port, router_port, created_at) VALUES ('mail', 8025, 18025, '2026-10-19 09:00:00')");
        });

        self::assertSame([['mail', 8025, 18025], ['typesense', 9108, 8108]], $rows);
    }

    public function testAnExistingPortWithTheNameIsKept(): void
    {
        $rows = $this->migrateAfter(static function ($connection): void {
            $connection->executeStatement("INSERT INTO named_port (name, public_port, router_port, created_at) VALUES ('typesense', 9200, 8200, '2026-10-19 09:00:00')");
        });

        self::assertSame([['typesense', 9200, 8200]], $rows);
    }

    public function testAPortOnTheSameNumbersIsKeptAndNothingFails(): void
    {
        $rows = $this->migrateAfter(static function ($connection): void {
            $connection->executeStatement("INSERT INTO named_port (name, public_port, router_port, created_at) VALUES ('search', 9108, 8108, '2026-10-19 09:00:00')");
        });

        self::assertSame([['search', 9108, 8108]], $rows);
    }
}
