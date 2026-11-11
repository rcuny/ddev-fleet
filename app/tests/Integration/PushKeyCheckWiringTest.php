<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fleet\Ssh\PushKeyCheck;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * With a null cache pool the push-key check silently runs uncached (FLE-271), so the container must hand it a pool.
 */
final class PushKeyCheckWiringTest extends KernelTestCase
{
    public function testContainerInjectsANonNullCachePool(): void
    {
        self::bootKernel();

        $check = self::getContainer()->get(PushKeyCheck::class);
        self::assertInstanceOf(PushKeyCheck::class, $check);

        $cache = new \ReflectionProperty(PushKeyCheck::class, 'cache')->getValue($check);
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
    }
}
