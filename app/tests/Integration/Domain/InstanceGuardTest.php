<?php

declare(strict_types=1);

namespace App\Tests\Integration\Domain;

use App\Domain\Instance\InstanceGuard;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceGuardTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    public function testListsAndCountsOneProjectsInstances(): void
    {
        $blog = $this->projectNamed('blog');
        $shop = $this->projectNamed('shop');
        $guard = new InstanceGuard(new InstanceRepository($this->em));
        self::assertSame([], $guard->instanceIds($blog));

        $this->instancesOf($blog, [InstanceState::Running, InstanceState::Destroying]);
        $this->instancesOf($shop, [InstanceState::Stopped]);

        self::assertSame(['blog--i1', 'blog--i2'], $guard->instanceIds($blog));
        self::assertCount(2, $guard->instanceIds($blog));
        self::assertSame(['shop--i1'], $guard->instanceIds($shop));
    }

    public function testTheRealGuardIsWiredOutsideTheTestEnvironment(): void
    {
        $services = file_get_contents(\dirname(__DIR__, 3).'/config/services.yaml');
        self::assertIsString($services);

        self::assertMatchesRegularExpression('~ProjectInstanceGuard:\s+alias: App\\\\Domain\\\\Instance\\\\InstanceGuard~', $services);
    }
}
