<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Capacity;

use App\Fleet\Host\Capacity\SlotLocks;
use PHPUnit\Framework\TestCase;

/** FLE-212: busy is read from /proc/locks, never by probing with a lock; unreadable state is reported. */
final class SlotLocksTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-slotlocks-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/slots/p/t', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/slots/p/*/*') ?: [] as $f) {
            @chmod(\dirname($f), 0o775);
            @unlink($f);
        }
        @chmod($this->dir.'/slots/p/t', 0o775);
        @unlink($this->dir.'/locks');
        @rmdir($this->dir.'/slots/p/t');
        @rmdir($this->dir.'/slots/p');
        @rmdir($this->dir.'/slots');
        @rmdir($this->dir);
    }

    private function lockLine(string $file, string $kind = 'FLOCK', string $prefix = ''): string
    {
        $stat = stat($file);
        self::assertIsArray($stat);

        return \sprintf("1: %s%s  ADVISORY  WRITE 4242 %s:%d 0 EOF\n", $prefix, $kind, SlotLocks::deviceId($stat['dev']), $stat['ino']);
    }

    public function testBusyFromProcLocksFile(): void
    {
        $pool = $this->dir.'/slots/p/t';
        touch($pool.'/a.lock');
        touch($pool.'/b.lock');
        file_put_contents($this->dir.'/locks', $this->lockLine($pool.'/a.lock')."2: -> FLOCK  ADVISORY  WRITE 9 00:00:1 0 EOF\n".$this->lockLine($pool.'/b.lock', 'FLOCK', '-> '));
        $locks = new SlotLocks($this->dir.'/slots', $this->dir.'/locks');

        self::assertSame(['busy' => 1, 'unreadable' => 0], $locks->inspect('p', 't'), 'a waiter ("->") is not a holder');
        self::assertSame(0, $locks->busy('p', null));
        self::assertSame(0, $locks->busy('absent', 't'));
    }

    public function testSharedLockHolderIsBusyAndTheProbeNeverLocks(): void
    {
        $pool = $this->dir.'/slots/p/t';
        touch($pool.'/a.lock');
        $locks = new SlotLocks($this->dir.'/slots');
        $holder = fopen($pool.'/a.lock', 'r');
        self::assertNotFalse($holder);
        flock($holder, \LOCK_SH);

        self::assertSame(1, $locks->busy('p', 't'), 'a shared holder is busy (the old probe said free)');
        // Asking must not disturb a runner: the exclusive non-blocking lock of a free file still succeeds at once.
        flock($holder, \LOCK_UN);
        self::assertSame(0, $locks->busy('p', 't'));
        $runner = fopen($pool.'/a.lock', 'c');
        self::assertNotFalse($runner);
        self::assertTrue(flock($runner, \LOCK_EX | \LOCK_NB));
        self::assertSame(1, $locks->busy('p', 't'));
        fclose($runner);
        fclose($holder);
    }

    public function testUnreadableLockTableIsReportedNotSkipped(): void
    {
        $pool = $this->dir.'/slots/p/t';
        touch($pool.'/a.lock');
        touch($pool.'/b.lock');
        $locks = new SlotLocks($this->dir.'/slots', $this->dir.'/no-such-locks');

        self::assertSame(['busy' => 0, 'unreadable' => 2], $locks->inspect('p', 't'));
    }

    public function testUnreadablePoolDirectoryIsReported(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root reads any directory');
        }
        $pool = $this->dir.'/slots/p/t';
        touch($pool.'/a.lock');
        chmod($pool, 0o000);

        self::assertSame(['busy' => 0, 'unreadable' => 1], new SlotLocks($this->dir.'/slots')->inspect('p', 't'));
    }
}
