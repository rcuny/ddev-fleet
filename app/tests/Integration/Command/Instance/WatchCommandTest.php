<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Domain\Instance\InstanceState;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\RunsFleetCommands;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WatchCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;
    use RunsFleetCommands;

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

    public function testOnceRunsOneTick(): void
    {
        [$instance] = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running]);

        $tester = $this->cli('fleet:instance:watch', ['--once' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $this->em->clear();
        $seen = $this->em->find($instance::class, $instance->id());
        self::assertNotNull($seen);
        // The recording runner prints no project list, so ddev "did not answer": the row is observed as unknown.
        self::assertSame(InstanceState::Unknown, $seen->state());
        self::assertNotNull($seen->lastObservedAt());
    }

    public function testABadIntervalIsRefused(): void
    {
        foreach (['0', 'abc', '-5', '99999'] as $interval) {
            $tester = $this->cli('fleet:instance:watch', ['--once' => true, '--interval' => $interval]);

            self::assertSame(2, $tester->getStatusCode(), $interval);
        }
    }
}
