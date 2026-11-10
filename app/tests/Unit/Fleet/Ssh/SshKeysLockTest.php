<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\FleetPaths;
use App\Fleet\Ssh\SshKeysLock;
use PHPUnit\Framework\TestCase;

final class SshKeysLockTest extends TestCase
{
    private string $home;
    private SshKeysLock $lock;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle270-lock-'.bin2hex(random_bytes(4));
        $this->lock = new SshKeysLock(new FleetPaths($this->home, $this->home.'/caddy'));
    }

    protected function tearDown(): void
    {
        @unlink($this->home.'/ssh/.keys.lock');
        @rmdir($this->home.'/ssh');
        @rmdir($this->home);
    }

    private function tryLock(): bool
    {
        $handle = fopen($this->home.'/ssh/.keys.lock', 'c');
        self::assertNotFalse($handle);
        $got = flock($handle, \LOCK_EX | \LOCK_NB);
        $got && flock($handle, \LOCK_UN);
        fclose($handle);

        return $got;
    }

    public function testASecondHolderCannotTakeTheLockWhileTheFirstHoldsIt(): void
    {
        $during = null;
        $result = $this->lock->run(function () use (&$during): string {
            $during = $this->tryLock();

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertFalse($during, 'a second holder is refused while the work runs');
        self::assertTrue($this->tryLock(), 'the lock is released afterwards');
    }

    public function testTheLockIsReleasedWhenTheWorkThrows(): void
    {
        $this->expectException(\LogicException::class);
        try {
            $this->lock->run(static function (): never {
                throw new \LogicException('boom');
            });
        } finally {
            self::assertTrue($this->tryLock());
        }
    }
}
