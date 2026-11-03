<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TempFleetHome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SqliteImmediateTransactionTest extends KernelTestCase
{
    use TempFleetHome;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreFleetHome();
    }

    public function testATransactionTakesTheWriteLockWhenItStarts(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $first = $this->connection('doctrine.dbal.default_connection');
        $first->executeStatement('CREATE TABLE lock_probe (id INTEGER PRIMARY KEY)');

        $first->beginTransaction();
        try {
            // No write yet, and a second connection still cannot start a write transaction (it would wait, here: fail
            // at once because its busy timeout is zero). A deferred BEGIN would succeed.
            $second = $this->secondConnection('doctrine.dbal.default_connection');
            $this->expectException(LockWaitTimeoutException::class);
            $second->beginTransaction();
        } finally {
            $first->rollBack();
        }
    }

    public function testCommitRollbackAndSavepointsStillWork(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $connection = $this->connection('doctrine.dbal.default_connection');
        $connection->executeStatement('CREATE TABLE lock_probe (id INTEGER PRIMARY KEY)');

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO lock_probe (id) VALUES (1)');
        $connection->beginTransaction(); // savepoint
        $connection->executeStatement('INSERT INTO lock_probe (id) VALUES (2)');
        $connection->rollBack();
        $connection->commit();

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO lock_probe (id) VALUES (3)');
        $connection->rollBack();

        self::assertSame([1], array_map('intval', $connection->fetchFirstColumn('SELECT id FROM lock_probe ORDER BY id')));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testTheQueueConnectionIsCoveredToo(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $queue = $this->connection('doctrine.dbal.queue_connection');
        $queue->executeStatement('CREATE TABLE lock_probe (id INTEGER PRIMARY KEY)');

        $queue->beginTransaction();
        try {
            $other = $this->secondConnection('doctrine.dbal.queue_connection');
            $this->expectException(LockWaitTimeoutException::class);
            $other->beginTransaction();
        } finally {
            $queue->rollBack();
        }
    }

    private function connection(string $serviceId): Connection
    {
        $connection = self::getContainer()->get($serviceId);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * A second connection to the same database file, built with the container connection's configuration (so it runs
     * through the same middleware chain), with a zero busy timeout: a lock conflict fails at once instead of waiting.
     */
    private function secondConnection(string $serviceId): Connection
    {
        $first = $this->connection($serviceId);
        $second = DriverManager::getConnection($first->getParams(), $first->getConfiguration());
        $second->executeStatement('PRAGMA busy_timeout = 0');

        return $second;
    }
}
