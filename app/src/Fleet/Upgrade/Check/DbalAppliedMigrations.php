<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use Doctrine\DBAL\Connection;

final readonly class DbalAppliedMigrations implements AppliedMigrations
{
    public function __construct(private Connection $connection)
    {
    }

    public function names(): array
    {
        /** @var list<string> $versions */
        $versions = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');

        return array_map(static fn (string $v): string => ltrim(strrchr('\\'.$v, '\\') ?: $v, '\\'), $versions);
    }
}
