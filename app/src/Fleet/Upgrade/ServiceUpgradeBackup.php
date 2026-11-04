<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Backup\BackupService;

final readonly class ServiceUpgradeBackup implements UpgradeBackup
{
    public function __construct(private BackupService $backups)
    {
    }

    public function create(): string
    {
        return $this->backups->create()->name;
    }
}
