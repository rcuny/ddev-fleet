<?php

declare(strict_types=1);

namespace App\Fleet\Backup;

/**
 * What BackupRestorer::preflight() found out, before anything was touched. A restore needs ok().
 */
final readonly class RestorePlan
{
    /**
     * @param string|null  $queueBackup the queue sibling with the same stamp, when it exists
     * @param list<string> $refusals    why the restore must not start (empty: it may)
     * @param list<string> $activeUnits the fleet-worker@* and fleet-scheduler units that are running, as systemd names them
     */
    public function __construct(
        public string $fleetBackup,
        public ?string $queueBackup,
        public BackupVerification $verification,
        public array $refusals,
        public array $activeUnits,
    ) {
    }

    public function ok(): bool
    {
        return [] === $this->refusals;
    }
}
