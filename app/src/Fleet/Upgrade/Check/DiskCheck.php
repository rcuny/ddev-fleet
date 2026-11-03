<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\FleetPaths;
use App\Fleet\Upgrade\Plan;
use App\Health\HostHealth;
use App\Health\HostHealthReport;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The upgrade takes a backup first and then rewrites the checkout, so the disk must hold twice the database size
 * (the backup, and room for a restore) plus headroom for the new checkout and its dependencies.
 *
 * Findings: `disk.space` (Block, not overridable): free space is below the need. Remedy: free disk space (prune old
 * backups, destroy unused instances) and run the check again. An unknown free size gives no finding.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 500])]
final readonly class DiskCheck implements UpgradeCheck
{
    public const string SPACE = 'disk.space';
    public const int CHECKOUT_HEADROOM_BYTES = 512 * 1024 * 1024;

    public function __construct(private HostHealth $health, private FleetPaths $paths, private int $headroomBytes = self::CHECKOUT_HEADROOM_BYTES)
    {
    }

    public function check(Plan $plan): array
    {
        $free = $this->health->report()->diskFreeBytes;
        if (null === $free) {
            return [];
        }
        $need = 2 * $this->databaseBytes() + $this->headroomBytes;
        if ($free >= $need) {
            return [];
        }

        return [CheckResult::block(self::SPACE, \sprintf('%s free, and the upgrade needs about %s (twice the databases plus room for the new code).', HostHealthReport::bytes($free), HostHealthReport::bytes($need)), 'Free disk space (prune old backups, destroy unused instances) and run the check again.')];
    }

    private function databaseBytes(): int
    {
        $total = 0;
        foreach ([$this->paths->fleetDb(), $this->paths->queueDb()] as $db) {
            foreach ([$db, $db.'-wal', $db.'-shm'] as $file) {
                $total += is_file($file) ? (int) filesize($file) : 0;
            }
        }

        return $total;
    }
}
