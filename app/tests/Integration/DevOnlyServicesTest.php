<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Project\ProjectMembership;
use App\Security\StandIn\StandInProjectMembership;
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

    /**
     * The stand-in membership exists in test (VisibleInstancesTest relies on it); ProdKernelSecurityTest pins that the
     * compiled prod container has neither the class nor a mention of it, so production keeps the real membership.
     */
    public function testStandInMembershipIsAbsentInProd(): void
    {
        self::bootKernel();
        self::assertInstanceOf(StandInProjectMembership::class, self::getContainer()->get(ProjectMembership::class));

        $source = file_get_contents(\dirname(__DIR__, 2).'/src/Security/StandIn/StandInProjectMembership.php');
        self::assertIsString($source);
        self::assertStringContainsString("#[When(env: 'dev')]", $source);
        self::assertStringContainsString("#[When(env: 'test')]", $source);
        self::assertStringNotContainsString("#[When(env: 'prod')]", $source);
    }
}
