<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * One backup file in FLEET_HOME/backups.
 */
final readonly class BackupInfo
{
    public function __construct(
        public string $name,
        public BackupKind $kind,
        public int $size,
        public \DateTimeImmutable $modifiedAt,
    ) {
    }
}
