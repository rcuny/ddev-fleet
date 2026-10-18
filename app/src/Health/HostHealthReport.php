<?php

declare(strict_types=1);

namespace App\Health;

/**
 * Memory, disk and pending-reboot state of this machine, as the Home page shows them. A figure is null when the
 * machine does not report it (for example no /proc on a development laptop).
 */
final readonly class HostHealthReport
{
    public function __construct(
        public ?int $memoryTotalBytes,
        public ?int $memoryAvailableBytes,
        public ?int $diskTotalBytes,
        public ?int $diskFreeBytes,
        public bool $rebootRequired,
    ) {
    }

    public function memoryUsedPercent(): ?int
    {
        return self::usedPercent($this->memoryTotalBytes, $this->memoryAvailableBytes);
    }

    public function diskUsedPercent(): ?int
    {
        return self::usedPercent($this->diskTotalBytes, $this->diskFreeBytes);
    }

    /**
     * "1.5 GiB": binary units, one decimal from MiB up.
     */
    public static function bytes(?int $bytes): string
    {
        if (null === $bytes) {
            return 'unknown';
        }
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024.0 && $unit < \count($units) - 1) {
            $value /= 1024.0;
            ++$unit;
        }

        return $unit < 2 ? \sprintf('%d %s', (int) $value, $units[$unit]) : \sprintf('%.1f %s', $value, $units[$unit]);
    }

    private static function usedPercent(?int $total, ?int $free): ?int
    {
        if (null === $total || null === $free || $total <= 0) {
            return null;
        }

        return (int) round(100 * max(0, $total - $free) / $total);
    }
}
