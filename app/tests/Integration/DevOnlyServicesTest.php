<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ui\ProfilerToolbarSwitch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dev-only services must not exist in test (and, see ProdKernelSecurityTest, not in prod either).
 */
final class DevOnlyServicesTest extends KernelTestCase
{
    public function testTheProfilerToolbarSwitchIsAbsentOutsideDev(): void
    {
        self::bootKernel();

        self::assertSame('test', self::$kernel?->getEnvironment());
        self::assertFalse(self::getContainer()->has(ProfilerToolbarSwitch::class));
    }
}
