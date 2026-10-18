<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies the fleet pragmas (WAL, foreign keys, busy timeout) to every SQLite connection
 * and creates the database's parent directory on first connect.
 */
#[AsMiddleware]
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $path = $params['path'] ?? null;
                $isFile = \is_string($path) && '' !== $path && ':memory:' !== $path && !($params['memory'] ?? false);

                if ($isFile) {
                    $dir = \dirname($path);
                    if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                        throw new \RuntimeException(\sprintf('Cannot create SQLite directory "%s".', $dir));
                    }
                }

                $connection = parent::connect($params);

                if ($isFile) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA synchronous = NORMAL');
                }
                $connection->exec('PRAGMA foreign_keys = ON');
                $connection->exec('PRAGMA busy_timeout = 5000');

                return $connection;
            }
        };
    }
}
