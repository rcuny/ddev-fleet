<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fleet\FleetPaths;
use App\Tests\Support\TempFleetHome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class LockWiringTest extends KernelTestCase
{
    use TempFleetHome;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreFleetHome();
    }

    public function testFlockLocksAreExclusiveAndLiveUnderFleetHome(): void
    {
        $home = $this->useFreshFleetHome();
        self::bootKernel();

        $factory = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $factory);

        $first = $factory->createLock('instance:demo--main');
        $second = $factory->createLock('instance:demo--main');

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire(false));
        self::assertNotSame([], glob($home.'/locks/*') ?: []);

        $first->release();
        self::assertTrue($second->acquire(false));
        $second->release();
    }

    public function testLockDirectoryAgreesWithFleetPaths(): void
    {
        $this->useFreshFleetHome();
        self::bootKernel();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);

        $lock = self::getContainer()->get(LockFactory::class)->createLock('agree');
        self::assertTrue($lock->acquire());
        self::assertNotSame([], glob($paths->locksDir().'/*') ?: []);
        $lock->release();
    }
}
