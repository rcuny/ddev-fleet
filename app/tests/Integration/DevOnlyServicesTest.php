<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Project\ProjectMembership;
use App\Fleet\Jobs\Dev\FakeJobType;
use App\Fleet\Jobs\JobTypeRegistry;
use App\Security\StandIn\StandInProjectMembership;
use App\Ui\ProfilerToolbarSwitch;
use Symfony\Bundle\FrameworkBundle\Console\Application;
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
    public function testStandInMembershipIsWiredInTest(): void
    {
        self::bootKernel();

        self::assertInstanceOf(StandInProjectMembership::class, self::getContainer()->get(ProjectMembership::class));
    }

    /**
     * The fake job and its command exist in test (the Playwright specs use them in dev); the prod container check is in
     * ProdKernelSecurityTest.
     */
    public function testTheFakeJobIsRegisteredInTest(): void
    {
        $kernel = self::bootKernel();

        self::assertInstanceOf(FakeJobType::class, self::getContainer()->get(JobTypeRegistry::class)->get('fake'));
        $application = new Application($kernel);
        self::assertTrue($application->has('fleet:dev:fake-job'));
    }
}
