<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\FleetPaths;
use App\Fleet\Instances\DiskHeadroom;
use App\Fleet\Projects\Refused;
use App\Health\HostHealth;
use App\Health\HostHealthReport;
use PHPUnit\Framework\TestCase;

final class DiskHeadroomTest extends TestCase
{
    private function headroom(): DiskHeadroom
    {
        return new DiskHeadroom(new HostHealth(new FleetPaths('/tmp/fleet-x', '/tmp/fleet-x/caddy')));
    }

    public function testRoomIsAccepted(): void
    {
        $this->headroom()->assess(new HostHealthReport(null, null, 1000, 100, false));

        $this->expectNotToPerformAssertions();
    }

    public function testLessThanTenPercentIsRefusedWithTheFigure(): void
    {
        try {
            $this->headroom()->assess(new HostHealthReport(null, null, 1000, 99, false));
            self::fail('expected Refused');
        } catch (Refused $e) {
            self::assertStringContainsString('Only 10 % of the disk is free', $e->getMessage());
            self::assertStringContainsString('--skip-disk-check', $e->getMessage());
        }
    }

    public function testAnUnknownDiskDoesNotBlock(): void
    {
        $this->headroom()->assess(new HostHealthReport(null, null, null, null, false));

        $this->expectNotToPerformAssertions();
    }

    public function testEnsureReadsTheRealDisk(): void
    {
        $this->expectException(Refused::class);

        new DiskHeadroom(new HostHealth(new FleetPaths(sys_get_temp_dir(), sys_get_temp_dir().'/c')), 1.01)->ensure();
    }
}
