<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * RAM held by tmpfs (FLE-369). Debian 13 mounts /tmp as a tmpfs sized at half of the RAM, so a large file copied there
 * fills memory, not disk. Reads `Shmem` from /proc/meminfo and, when /tmp is a tmpfs of its own (/proc/self/mountinfo),
 * its size and use. Read-only and rootless. A value that cannot be read is null; nothing here ever throws.
 *
 * Warnings (the thresholds are public so docs and tests name them): /tmp above 50% of its size, Shmem above 25% of RAM.
 */
final readonly class TmpfsProbe
{
    public const int TMP_WARN_PERCENT = 50;
    public const int SHMEM_WARN_PERCENT = 25;

    public function __construct(
        #[Autowire('%fleet.meminfo_file%')]
        private string $meminfoFile = '/proc/meminfo',
        #[Autowire('%fleet.mountinfo_file%')]
        private string $mountinfoFile = '/proc/self/mountinfo',
        #[Autowire('%fleet.tmp_dir%')]
        private string $tmpDir = '/tmp',
    ) {
    }

    /**
     * @return array{shmem_mb: int|null, shmem_percent: int|null, tmp: array{tmpfs: bool, size_mb: int|null, used_mb: int|null, percent: int|null}, warnings: list<array{code: string, message: string}>}
     */
    public function read(int $ramMb): array
    {
        $meminfo = (string) @file_get_contents($this->meminfoFile);
        $shmem = 1 === preg_match('/^Shmem:\s*([0-9]+)\s*kB\s*$/mi', $meminfo, $m) ? intdiv((int) $m[1], 1024) : null;
        $shmemPercent = null !== $shmem && $ramMb > 0 ? intdiv($shmem * 100, $ramMb) : null;

        $tmpfs = $this->isTmpfs();
        $size = $used = $percent = null;
        if ($tmpfs) {
            $total = @disk_total_space($this->tmpDir);
            $free = @disk_free_space($this->tmpDir);
            if (false !== $total && false !== $free && $total > 0) {
                $size = intdiv((int) $total, 1048576);
                $used = intdiv((int) ($total - $free), 1048576);
                $percent = (int) floor(($total - $free) * 100 / $total);
            }
        }

        $warnings = [];
        if (null !== $percent && $percent > self::TMP_WARN_PERCENT) {
            $warnings[] = ['code' => 'tmp_tmpfs_high', 'message' => \sprintf('/tmp is a tmpfs (RAM) and is %d%% full (%d of %d MB). Move large files to /var/tmp or /srv: they take memory, and a full host stops answering.', $percent, $used, $size)];
        }
        if (null !== $shmemPercent && $shmemPercent > self::SHMEM_WARN_PERCENT) {
            $warnings[] = ['code' => 'shmem_high', 'message' => \sprintf('Shared memory (tmpfs and shm) holds %d MB, %d%% of the RAM. Look for large files in /tmp, /dev/shm or /run.', $shmem, $shmemPercent)];
        }

        return [
            'shmem_mb' => $shmem,
            'shmem_percent' => $shmemPercent,
            'tmp' => ['tmpfs' => $tmpfs, 'size_mb' => $size, 'used_mb' => $used, 'percent' => $percent],
            'warnings' => $warnings,
        ];
    }

    /** True when the tmp directory is itself a tmpfs mount point (the last matching mountinfo line wins). */
    private function isTmpfs(): bool
    {
        $found = false;
        foreach (explode("\n", (string) @file_get_contents($this->mountinfoFile)) as $line) {
            // "36 35 0:30 / /tmp rw,nosuid shared:5 - tmpfs tmpfs rw,size=4194304k"
            if (1 === preg_match('/^\S+ \S+ \S+ \S+ (\S+) .* - (\S+) /', $line, $m) && $m[1] === $this->tmpDir) {
                $found = 'tmpfs' === $m[2];
            }
        }

        return $found;
    }
}
