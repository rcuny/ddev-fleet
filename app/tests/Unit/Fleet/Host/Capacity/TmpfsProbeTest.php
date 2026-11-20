<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Capacity;

use App\Fleet\Host\Capacity\TmpfsProbe;
use PHPUnit\Framework\TestCase;

/** FLE-369: Shmem and the /tmp tmpfs, and when they warn. */
final class TmpfsProbeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-tmpfs-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/tmp', 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/meminfo');
        @unlink($this->dir.'/mountinfo');
        @rmdir($this->dir.'/tmp');
        @rmdir($this->dir);
    }

    private function probe(string $meminfo, string $mountinfo): TmpfsProbe
    {
        file_put_contents($this->dir.'/meminfo', $meminfo);
        file_put_contents($this->dir.'/mountinfo', $mountinfo);

        return new TmpfsProbe($this->dir.'/meminfo', $this->dir.'/mountinfo', $this->dir.'/tmp');
    }

    public function testShmemAboveAQuarterOfTheRamWarns(): void
    {
        $report = $this->probe("MemTotal: 65536000 kB\nShmem: 30310400 kB\n", '')->read(64000);

        self::assertSame(29600, $report['shmem_mb']);
        self::assertSame(46, $report['shmem_percent']);
        self::assertSame(['shmem_high'], array_column($report['warnings'], 'code'));
        self::assertFalse($report['tmp']['tmpfs']);
    }

    public function testQuietWhenShmemIsSmallAndTmpIsOnDisk(): void
    {
        $report = $this->probe("Shmem: 1048576 kB\n", "30 1 8:1 / / rw - ext4 /dev/sda1 rw\n")->read(64000);

        self::assertSame(1024, $report['shmem_mb']);
        self::assertSame([], $report['warnings']);
        self::assertFalse($report['tmp']['tmpfs']);
        self::assertNull($report['tmp']['percent']);
    }

    public function testATmpfsTmpIsDetectedByItsMountPoint(): void
    {
        $mounts = "30 1 8:1 / / rw - ext4 /dev/sda1 rw\n36 30 0:30 / {$this->dir}/tmp rw,nosuid shared:5 - tmpfs tmpfs rw,size=4194304k\n";
        $report = $this->probe('', $mounts)->read(64000);

        self::assertTrue($report['tmp']['tmpfs']);
        self::assertNull($report['shmem_mb'], 'no Shmem line: unknown, not zero');
    }

    public function testUnreadableFilesNeverThrow(): void
    {
        $report = new TmpfsProbe($this->dir.'/none', $this->dir.'/none', $this->dir.'/none')->read(0);

        self::assertSame([], $report['warnings']);
        self::assertNull($report['shmem_mb']);
    }
}
