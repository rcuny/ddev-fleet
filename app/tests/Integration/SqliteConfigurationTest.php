<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TempFleetHome;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SqliteConfigurationTest extends KernelTestCase
{
    use TempFleetHome;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreFleetHome();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function connections(): iterable
    {
        yield 'fleet' => ['doctrine.dbal.default_connection'];
        yield 'queue' => ['doctrine.dbal.queue_connection'];
    }

    #[DataProvider('connections')]
    public function testPragmasAreAppliedOnEveryConnection(string $serviceId): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();

        $connection = self::getContainer()->get($serviceId);
        self::assertInstanceOf(Connection::class, $connection);

        self::assertSame('wal', strtolower((string) $connection->fetchOne('PRAGMA journal_mode')));
        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame(5000, (int) $connection->fetchOne('PRAGMA busy_timeout'));
    }

    public function testTheTwoConnectionsUseDifferentFilesAndVarDirIsCreated(): void
    {
        $home = $this->useFreshFleetHome();
        self::bootKernel();
        self::assertDirectoryDoesNotExist($home.'/var');

        $fleet = self::getContainer()->get('doctrine.dbal.default_connection');
        $queue = self::getContainer()->get('doctrine.dbal.queue_connection');
        self::assertInstanceOf(Connection::class, $fleet);
        self::assertInstanceOf(Connection::class, $queue);

        $fleet->fetchOne('SELECT 1');
        $queue->fetchOne('SELECT 1');

        self::assertFileExists($home.'/var/fleet.sqlite');
        self::assertFileExists($home.'/var/queue.sqlite');
        self::assertNotSame(
            $fleet->getParams()['path'] ?? null,
            $queue->getParams()['path'] ?? null,
        );
    }
}
