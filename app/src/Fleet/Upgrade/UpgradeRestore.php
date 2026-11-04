<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * The database side of a rollback, as a seam: which backup files exist, and putting one back.
 */
interface UpgradeRestore
{
    /** @return list<string> */
    public function backupNames(): array;

    /**
     * Restores the named backup over the live databases. The services that write them must be stopped. No migration
     * runs: the installer of the older release does that with the older code.
     *
     * @throws \RuntimeException with the reasons when the restore is refused or fails (the previous databases are back)
     */
    public function restore(string $name): void;
}
