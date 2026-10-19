<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Makes every SQLite transaction take the write lock when it starts (BEGIN IMMEDIATE instead of PDO's deferred BEGIN).
 *
 * A deferred transaction that reads and then writes cannot wait for the lock upgrade: once another connection has
 * committed in between, SQLite answers SQLITE_BUSY at once, without honouring busy_timeout ("database is locked").
 * With the lock taken first, a second writer waits for the first (up to the busy timeout set by SqlitePragmaMiddleware).
 * Every Doctrine flush and every explicit transaction goes through here, so no write path needs its own workaround.
 * Reads outside a transaction stay lock-free (WAL).
 *
 * PDO's own transaction methods are bypassed (they would issue a deferred BEGIN); DBAL keeps tracking the nesting level
 * and savepoints itself, and the three statements are the whole protocol.
 */
#[AsMiddleware]
final class SqliteImmediateTransactionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function beginTransaction(): void
                    {
                        $this->exec('BEGIN IMMEDIATE');
                    }

                    public function commit(): void
                    {
                        $this->exec('COMMIT');
                    }

                    public function rollBack(): void
                    {
                        $this->exec('ROLLBACK');
                    }
                };
            }
        };
    }
}
