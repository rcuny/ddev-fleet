<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Read\CapacityVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapacityVerdictTest extends TestCase
{
    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    private static function report(array $project = [], array $template = [], int $count = 1, int $max = 10): array
    {
        return [
            'instances' => ['count' => $count, 'max' => $max],
            'projects' => [$project + ['name' => 'p', 'slots' => 2, 'busy' => 0, 'effective_threads' => 8, 'used_cores' => 0.0, 'templates' => [$template + ['name' => 't', 'slots' => 2, 'busy' => 0, 'effective_threads' => 4]]]],
        ];
    }

    public function testRoomEverywhereIsOk(): void
    {
        $v = CapacityVerdict::evaluate(self::report(), 'p', 't');

        self::assertNotNull($v);
        self::assertTrue($v['ok']);
        self::assertSame([], $v['reasons']);
        self::assertSame(2.0, $v['cores']['need']);
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $template
     */
    #[DataProvider('full')]
    public function testEachLimitRefuses(array $project, array $template, int $count, string $reason): void
    {
        $v = CapacityVerdict::evaluate(self::report($project, $template, $count), 'p', 't');

        self::assertNotNull($v);
        self::assertFalse($v['ok']);
        self::assertSame([$reason], $v['reasons']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, int, string}>
     */
    public static function full(): iterable
    {
        yield 'template slots' => [[], ['busy' => 2], 1, 'template_slots_full'];
        yield 'project slots' => [['busy' => 2], [], 1, 'project_slots_full'];
        yield 'cpu' => [['used_cores' => 7.5], [], 1, 'cpu_full'];
        yield 'instance cap' => [[], [], 10, 'instance_cap'];
    }

    public function testUnlimitedSlotsAreNeverFullAndNeedFallsBackToTwo(): void
    {
        $v = CapacityVerdict::evaluate(self::report(['slots' => null, 'busy' => 50], ['slots' => null, 'busy' => 50]), 'p', 't');

        self::assertNotNull($v);
        self::assertTrue($v['ok']);
        self::assertSame(2.0, $v['cores']['need']);
    }

    public function testAnUnknownProjectIsNull(): void
    {
        self::assertNull(CapacityVerdict::evaluate(self::report(), 'ghost', 't'));
    }
}
