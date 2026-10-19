<?php

declare(strict_types=1);

namespace App\Health;

use App\Fleet\FleetPaths;

/**
 * Reads the machine's memory, disk space and "reboot required" flag for the Home page. Pure PHP file reads: no
 * process runs, so it is safe inside an HTTP request. The disk figures are for the file system holding FLEET_HOME,
 * which is where instances and their data grow.
 */
final readonly class HostHealth
{
    private const int MEMINFO_READ_BYTES = 8192;

    public function __construct(
        private FleetPaths $paths,
        private string $meminfoFile = '/proc/meminfo',
        private string $rebootFlagFile = '/run/reboot-required',
    ) {
    }

    public function report(): HostHealthReport
    {
        [$memoryTotal, $memoryAvailable] = $this->memory();
        [$diskTotal, $diskFree] = $this->disk();

        return new HostHealthReport($memoryTotal, $memoryAvailable, $diskTotal, $diskFree, is_file($this->rebootFlagFile));
    }

    /**
     * @return array{int|null, int|null} total and available bytes
     */
    private function memory(): array
    {
        $text = @file_get_contents($this->meminfoFile, false, null, 0, self::MEMINFO_READ_BYTES);
        if (false === $text) {
            return [null, null];
        }
        $found = [];
        if (false !== preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+) kB$/m', $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $found[$match[1]] = (int) $match[2] * 1024;
            }
        }

        return [$found['MemTotal'] ?? null, $found['MemAvailable'] ?? null];
    }

    /**
     * @return array{int|null, int|null} total and free bytes
     */
    private function disk(): array
    {
        $dir = $this->paths->home();
        while (!is_dir($dir) && '/' !== $dir && '.' !== $dir && '' !== $dir) {
            $dir = \dirname($dir);
        }
        $total = @disk_total_space($dir);
        $free = @disk_free_space($dir);

        return [false === $total ? null : (int) $total, false === $free ? null : (int) $free];
    }
}
