<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Fleet\Projects\Refused;
use App\Health\HostHealth;
use App\Health\HostHealthReport;

/**
 * A batch deploy needs room: refuse it when less than 10 % of the disk holding FLEET_HOME is free.
 */
final readonly class DiskHeadroom
{
    public function __construct(private HostHealth $health, private float $minFreeFraction = 0.10)
    {
    }

    /**
     * @throws Refused when the disk is too full
     */
    public function ensure(): void
    {
        $this->assess($this->health->report());
    }

    public function assess(HostHealthReport $report): void
    {
        $total = $report->diskTotalBytes;
        $free = $report->diskFreeBytes;
        if (null === $total || null === $free || $total <= 0) {
            return; // unknown: do not block
        }

        if ($free / $total < $this->minFreeFraction) {
            throw new Refused(\sprintf('Only %d %% of the disk is free (at least %d %% is needed for a batch). Pass --skip-disk-check to override.', (int) round(100 * $free / $total), (int) round(100 * $this->minFreeFraction)));
        }
    }
}
