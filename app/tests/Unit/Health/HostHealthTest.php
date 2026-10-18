<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Fleet\FleetPaths;
use App\Health\HostHealth;
use App\Health\HostHealthReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostHealthTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/host-health-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function health(string $meminfo, string $flag): HostHealth
    {
        return new HostHealth(new FleetPaths($this->dir, $this->dir.'/caddy'), $meminfo, $flag);
    }

    public function testReadsMemoryFromMeminfoAndTheRebootFlag(): void
    {
        file_put_contents($this->dir.'/meminfo', "MemTotal:       16384000 kB\nMemFree:         1000000 kB\nMemAvailable:    4096000 kB\nBuffers: 1 kB\n");
        file_put_contents($this->dir.'/flag', '*** System restart required ***');

        $report = $this->health($this->dir.'/meminfo', $this->dir.'/flag')->report();

        self::assertSame(16384000 * 1024, $report->memoryTotalBytes);
        self::assertSame(4096000 * 1024, $report->memoryAvailableBytes);
        self::assertSame(75, $report->memoryUsedPercent());
        self::assertTrue($report->rebootRequired);
        self::assertGreaterThan(0, $report->diskTotalBytes);
        self::assertNotNull($report->diskUsedPercent());
    }

    public function testMissingFilesAreReportedAsUnknownNotAsErrors(): void
    {
        $report = $this->health($this->dir.'/none', $this->dir.'/none-either')->report();

        self::assertNull($report->memoryTotalBytes);
        self::assertNull($report->memoryUsedPercent());
        self::assertFalse($report->rebootRequired);
    }

    public function testAMeminfoWithoutTheFiguresGivesNull(): void
    {
        file_put_contents($this->dir.'/meminfo', "garbage\nMemTotal: lots kB\n");

        $report = $this->health($this->dir.'/meminfo', $this->dir.'/flag')->report();

        self::assertNull($report->memoryTotalBytes);
        self::assertNull($report->memoryAvailableBytes);
    }

    public function testTheDiskFiguresComeFromTheNearestExistingParentOfAHomeThatDoesNotExistYet(): void
    {
        $health = new HostHealth(new FleetPaths($this->dir.'/not/created/yet', $this->dir.'/caddy'), $this->dir.'/x', $this->dir.'/y');

        self::assertGreaterThan(0, $health->report()->diskTotalBytes);
    }

    /**
     * @return iterable<string, array{int|null, string}>
     */
    public static function sizes(): iterable
    {
        yield 'unknown' => [null, 'unknown'];
        yield 'bytes' => [512, '512 B'];
        yield 'kib' => [1536, '1 KiB'];
        yield 'mib' => [5 * 1024 * 1024, '5.0 MiB'];
        yield 'gib' => [(int) (1.5 * 1024 ** 3), '1.5 GiB'];
        yield 'tib' => [3 * 1024 ** 4, '3.0 TiB'];
        yield 'negative' => [-5, '0 B'];
    }

    #[DataProvider('sizes')]
    public function testBytesAreHumanReadable(?int $bytes, string $expected): void
    {
        self::assertSame($expected, HostHealthReport::bytes($bytes));
    }

    public function testPercentagesGuardAgainstZeroAndInconsistentFigures(): void
    {
        self::assertNull(new HostHealthReport(0, 0, null, null, false)->memoryUsedPercent());
        self::assertSame(0, new HostHealthReport(100, 500, null, null, false)->memoryUsedPercent());
        self::assertNull(new HostHealthReport(null, 5, null, null, false)->memoryUsedPercent());
    }
}
