<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Capacity;

use App\Fleet\Host\Capacity\SlotLocks;
use App\Fleet\Host\Capacity\UsageSampler;
use PHPUnit\Framework\TestCase;

/** FLE-200: used cores from cpu.stat deltas, throttling, stale readings, and busy from held flocks. */
final class UsageSamplerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/fleet-capacity-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/cg/fleet-ci.slice/fleet-ci-gates.slice', 0o775, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    private function stat(string $slice, int $usec, int $throttled): void
    {
        $path = $this->dir.'/cg'.$slice.'/cpu.stat';
        file_put_contents($path, "usage_usec $usec\nuser_usec 1\nsystem_usec 1\nnr_periods 9\nnr_throttled $throttled\nthrottled_usec 0\n");
    }

    private function sampler(): UsageSampler
    {
        return new UsageSampler($this->dir.'/cg', $this->dir.'/usage.json');
    }

    public function testUsedCoresIsTheDeltaOverTheElapsedTime(): void
    {
        $this->stat('', 10_000_000, 0);
        $this->stat('/fleet-ci.slice', 4_000_000, 0);
        $this->stat('/fleet-ci.slice/fleet-ci-gates.slice', 1_000_000, 0);
        $t0 = new \DateTimeImmutable('2026-11-05 12:00:00');
        self::assertSame([], $this->sampler()->sample($t0), 'the first sample has nothing to compare with');

        $this->stat('', 10_000_000 + 40_000_000, 0);
        $this->stat('/fleet-ci.slice', 4_000_000 + 25_000_000, 3);
        $this->stat('/fleet-ci.slice/fleet-ci-gates.slice', 1_000_000 + 5_000_000, 0);
        $t1 = $t0->modify('+10 seconds');
        $used = $this->sampler()->sample($t1);

        self::assertSame(2.5, $used['fleet-ci.slice']['used_cores']);
        self::assertTrue($used['fleet-ci.slice']['throttled']);
        self::assertSame(0.5, $used['fleet-ci-gates.slice']['used_cores']);
        self::assertFalse($used['fleet-ci-gates.slice']['throttled']);

        // another process reads the stored result
        self::assertEquals($used, $this->sampler()->usage($t1->modify('+30 seconds')));
        self::assertSame([], $this->sampler()->usage($t1->modify('+'.(UsageSampler::STALE_SECONDS + 1).' seconds')), 'stale = unknown');
    }

    public function testRestartedCountersAreSkipped(): void
    {
        $this->stat('/fleet-ci.slice', 9_000_000, 0);
        $t0 = new \DateTimeImmutable('2026-11-05 12:00:00');
        $this->sampler()->sample($t0);
        $this->stat('/fleet-ci.slice', 1_000_000, 0);

        self::assertArrayNotHasKey('fleet-ci.slice', $this->sampler()->sample($t0->modify('+10 seconds')));
    }

    public function testMissingTreeAndCorruptFileAreHarmless(): void
    {
        $sampler = new UsageSampler($this->dir.'/nope', $this->dir.'/usage.json');
        file_put_contents($this->dir.'/usage.json', 'not json');
        self::assertSame([], $sampler->sample(new \DateTimeImmutable()));
        self::assertSame([], $sampler->usage(new \DateTimeImmutable()));
    }

    public function testBusyCountsHeldLocksOnly(): void
    {
        $pool = $this->dir.'/slots/ddev-fleet-ci/gates';
        mkdir($pool, 0o775, true);
        mkdir($this->dir.'/slots/ddev-fleet-ci/_project', 0o775, true);
        touch($pool.'/1.lock');
        touch($pool.'/2.lock');
        touch($pool.'/3.lock');
        touch($this->dir.'/slots/ddev-fleet-ci/_project/pw.lock');
        $locks = new SlotLocks($this->dir.'/slots');
        self::assertSame(0, $locks->busy('ddev-fleet-ci', 'gates'));

        $a = fopen($pool.'/1.lock', 'c');
        $b = fopen($pool.'/3.lock', 'c');
        self::assertNotFalse($a);
        self::assertNotFalse($b);
        flock($a, \LOCK_EX);
        flock($b, \LOCK_EX);
        self::assertSame(2, $locks->busy('ddev-fleet-ci', 'gates'));
        self::assertSame(0, $locks->busy('ddev-fleet-ci', null));
        self::assertSame(0, $locks->busy('absent', 'pool'), 'no directory = nothing busy');

        flock($a, \LOCK_UN);
        self::assertSame(1, $locks->busy('ddev-fleet-ci', 'gates'));
        fclose($a);
        fclose($b);
    }
}
