<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Backup\BackupKind;
use App\Fleet\Backup\BackupRestorer;
use App\Fleet\Backup\BackupService;
use App\Fleet\Backup\RestoreFailed;

final readonly class ServiceUpgradeRestore implements UpgradeRestore
{
    public function __construct(private BackupService $backups, private BackupRestorer $restorer)
    {
    }

    public function backupNames(): array
    {
        return array_map(static fn ($info): string => $info->name, $this->backups->list(BackupKind::Fleet));
    }

    public function restore(string $name): void
    {
        $plan = $this->restorer->preflight($this->backups->path($name));
        if (!$plan->ok()) {
            throw new \RuntimeException('The restore was refused: '.implode(' ', $plan->refusals));
        }
        try {
            $this->restorer->restore($plan, static function (): void {
            });
        } catch (RestoreFailed $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
