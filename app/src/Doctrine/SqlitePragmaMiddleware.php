<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Fleet\FleetPaths;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies the fleet pragmas (busy timeout, WAL, foreign keys) to every SQLite connection.
 * The FLEET_HOME layout (including var/) is owned by FleetPaths::ensureLayout(), which this
 * calls before the first connect to a fleet database.
 */
#[AsMiddleware]
final readonly class SqlitePragmaMiddleware implements Middleware
{
    public function __construct(private FleetPaths $paths)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $paths = $this->paths;

        return new class($driver, $paths) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly FleetPaths $paths)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $path = $params['path'] ?? null;
                $isFile = \is_string($path) && '' !== $path && ':memory:' !== $path && !($params['memory'] ?? false);

                if ($isFile) {
                    $dir = \dirname($path);
                    if ($dir === $this->paths->varDir()) {
                        $this->paths->ensureLayout();
                    } elseif (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                        throw new \RuntimeException(\sprintf('Cannot create SQLite directory "%s".', $dir));
                    }
                }

                $connection = parent::connect($params);

                // busy_timeout first: switching to WAL needs a lock and must wait rather than fail.
                $connection->exec('PRAGMA busy_timeout = 5000');
                if ($isFile) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA synchronous = NORMAL');
                }
                $connection->exec('PRAGMA foreign_keys = ON');

                return $connection;
            }
        };
    }
}
