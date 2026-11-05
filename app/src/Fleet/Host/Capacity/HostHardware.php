<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

/**
 * What the host has (FLE-200): logical CPUs, physical cores and RAM. Detected on demand, never stored, so a resized or
 * migrated host is never stale. The parsers take the text of `nproc`, `lscpu` and /proc/meminfo so tests can feed
 * fixtures (ddev5: 48 threads, 24 cores).
 */
final readonly class HostHardware
{
    public function __construct(public int $threads, public int $cores, public int $ramMb)
    {
    }

    /** The first positive whole number of the text (`nproc` prints one), or null. */
    public static function parseThreads(string $nproc): ?int
    {
        return 1 === preg_match('/^\s*([0-9]{1,6})\s*$/', $nproc, $m) && (int) $m[1] > 0 ? (int) $m[1] : null;
    }

    /** Sockets x cores per socket from `lscpu` (English or not: the labels are matched case-insensitively), or null. */
    public static function parseCores(string $lscpu): ?int
    {
        $sockets = $perSocket = null;
        foreach (preg_split('/\R/', $lscpu) ?: [] as $line) {
            if (1 === preg_match('/^\s*Socket\(s\):\s*([0-9]+)\s*$/i', $line, $m)) {
                $sockets = (int) $m[1];
            } elseif (1 === preg_match('/^\s*Core\(s\) per socket:\s*([0-9]+)\s*$/i', $line, $m)) {
                $perSocket = (int) $m[1];
            }
        }

        return null !== $sockets && null !== $perSocket && $sockets * $perSocket > 0 ? $sockets * $perSocket : null;
    }

    /** MemTotal of /proc/meminfo in whole MiB, or null. */
    public static function parseRamMb(string $meminfo): ?int
    {
        return 1 === preg_match('/^MemTotal:\s*([0-9]+)\s*kB\s*$/mi', $meminfo, $m) && (int) $m[1] > 0 ? intdiv((int) $m[1], 1024) : null;
    }
}
