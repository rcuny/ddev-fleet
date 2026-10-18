<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fleet\FleetPaths;
use App\Tests\Support\TempFleetHome;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The env defaults and FleetPaths both encode the on-disk layout; pin that they agree.
 */
final class FleetPathsAgreeWithWiringTest extends KernelTestCase
{
    use TempFleetHome;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreFleetHome();
    }

    public function testDatabaseFilesMatchTheConnectionPaths(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $container = self::getContainer();

        $paths = $container->get(FleetPaths::class);
        $fleet = $container->get('doctrine.dbal.default_connection');
        $queue = $container->get('doctrine.dbal.queue_connection');
        self::assertInstanceOf(FleetPaths::class, $paths);
        self::assertInstanceOf(Connection::class, $fleet);
        self::assertInstanceOf(Connection::class, $queue);

        self::assertSame($paths->fleetDb(), $fleet->getParams()['path'] ?? null);
        self::assertSame($paths->queueDb(), $queue->getParams()['path'] ?? null);
    }
}
