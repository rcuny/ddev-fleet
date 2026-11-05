<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Capacity;

use App\Fleet\Host\Capacity\HostHardware;
use App\Fleet\Host\Capacity\SliceName;
use PHPUnit\Framework\TestCase;

/** FLE-200: the detected values come out right for the ddev5 fixtures (48 threads, 24 cores, 188 GB). */
final class HostHardwareTest extends TestCase
{
    private const string LSCPU_DDEV5 = <<<'TXT'
        Architecture:                         x86_64
        CPU(s):                               48
        Thread(s) per core:                   2
        Core(s) per socket:                   12
        Socket(s):                            2
        Model name:                           Intel(R) Xeon(R) Gold
        TXT;

    public function testDdev5Fixtures(): void
    {
        self::assertSame(48, HostHardware::parseThreads("48\n"));
        self::assertSame(24, HostHardware::parseCores(self::LSCPU_DDEV5));
        self::assertSame(188 * 1024, HostHardware::parseRamMb("MemTotal:       197132288 kB\nMemFree: 1 kB\n"));
    }

    public function testSingleSocketHost(): void
    {
        self::assertSame(6, HostHardware::parseCores("Core(s) per socket:   6\nSocket(s):            1\n"));
    }

    public function testUnreadableTextGivesNull(): void
    {
        self::assertNull(HostHardware::parseThreads(''));
        self::assertNull(HostHardware::parseThreads('0'));
        self::assertNull(HostHardware::parseThreads('lots'));
        self::assertNull(HostHardware::parseCores("Socket(s): 2\n"));
        self::assertNull(HostHardware::parseCores(''));
        self::assertNull(HostHardware::parseRamMb('MemFree: 4 kB'));
    }

    public function testSliceNamesEncodeDashes(): void
    {
        self::assertSame('fleet-ddev_fleet_ci.slice', SliceName::project('ddev-fleet-ci'));
        self::assertSame('fleet-ddev_fleet_ci-gates.slice', SliceName::template('ddev-fleet-ci', 'gates'));
        self::assertSame('fleet-shop-ci_full.slice', SliceName::template('shop', 'ci-full'));
    }
}
