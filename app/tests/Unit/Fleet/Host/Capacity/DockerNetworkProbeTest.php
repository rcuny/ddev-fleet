<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Capacity;

use App\Fleet\Host\Capacity\DockerNetworkProbe;
use PHPUnit\Framework\TestCase;

/** FLE-219: how many bridge networks the daemon's address pools hold. */
final class DockerNetworkProbeTest extends TestCase
{
    public function testNoConfiguredPoolMeansTheBuiltInOnes(): void
    {
        self::assertSame(31, DockerNetworkProbe::capacity("null\n"));
        self::assertSame(31, DockerNetworkProbe::capacity(''));
        self::assertSame(31, DockerNetworkProbe::capacity('[]'));
        self::assertSame(31, DockerNetworkProbe::capacity('garbage'));
    }

    public function testPoolsAreSummed(): void
    {
        $json = '[{"Base":"172.17.0.0/16","Size":16},{"Base":"192.168.0.0/16","Size":20},{"Base":"10.64.0.0/10","Size":24}]';

        self::assertSame(1 + 16 + 16384, DockerNetworkProbe::capacity($json));
    }

    public function testNonsensePoolsAreIgnored(): void
    {
        self::assertSame(31, DockerNetworkProbe::capacity('[{"Base":"10.0.0.0/24","Size":16},{"x":1}]'));
    }
}
