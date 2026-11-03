<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * A finished restore. The previous databases are kept (never pruned): `previousFleet` / `previousQueue` are their
 * paths, null when there was no live file to keep.
 */
final readonly class RestoreResult
{
    public function __construct(
        public string $fleetBackup,
        public ?string $queueBackup,
        public ?string $previousFleet,
        public ?string $previousQueue,
    ) {
    }
}
