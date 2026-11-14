<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\PortMirror;

use App\Fleet\PortMirror\MirrorLock;
use App\Fleet\PortMirror\MirrorLockBusy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** FLE-336: enable took the router-mirror lock, restarted the router and waited the full bound for a second grab of its own lock. */
final class MirrorLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-mirror-lock-'.bin2hex(random_bytes(5));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    private function lock(float $wait = 0.0): MirrorLock
    {
        return new MirrorLock(new LockFactory(new FlockStore($this->dir)), $wait);
    }

    public function testANestedRunInTheSameProcessDoesNotWaitForItsOwnLock(): void
    {
        $lock = $this->lock(0.0);
        $started = microtime(true);

        $result = $lock->run(static fn () => $lock->run(static fn (): string => 'inner'));

        self::assertSame('inner', $result);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testAnotherObjectInTheSameProcessIsReentrantToo(): void
    {
        $outer = $this->lock(0.0);
        $inner = $this->lock(0.0);

        self::assertSame('ok', $outer->run(static fn () => $inner->run(static fn (): string => 'ok')));
    }

    public function testTheFileLockIsTakenOnceAndReleasedAfterTheOutermostRun(): void
    {
        $lock = $this->lock(0.0);
        $other = new LockFactory(new FlockStore($this->dir));

        $lock->run(static function () use ($lock, $other): void {
            $lock->run(static function (): void {
            });
            // Still held after the inner run returned: a second process (here a second handle) cannot take it.
            self::assertFalse($other->createLock('router-mirror', 900.0)->acquire(false));
        });

        $free = $other->createLock('router-mirror', 900.0);
        self::assertTrue($free->acquire(false), 'released once the outermost run ended');
        $free->release();
    }

    public function testAnExceptionInsideStillReleasesAndResetsTheNesting(): void
    {
        $lock = $this->lock(0.0);
        try {
            $lock->run(static function () use ($lock): void {
                $lock->run(static function (): void {
                    throw new \RuntimeException('boom');
                });
            });
        } catch (\RuntimeException) {
            // expected: the lock must still be released and the nesting reset
        }

        self::assertSame('again', $lock->run(static fn (): string => 'again'));
    }

    public function testAHandleOfAnotherProcessStillMakesItBusy(): void
    {
        $other = new LockFactory(new FlockStore($this->dir));
        $held = $other->createLock('router-mirror', 900.0);
        self::assertTrue($held->acquire(false));

        $busy = false;
        try {
            $this->lock(0.0)->run(static fn (): string => 'never');
        } catch (MirrorLockBusy) {
            $busy = true;
        }
        $held->release();

        self::assertTrue($busy);
    }
}
