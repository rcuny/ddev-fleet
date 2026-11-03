<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Schedules;

use App\Fleet\Schedules\CronSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase
{
    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private static function show(?\DateTimeImmutable $at): ?string
    {
        return $at?->format('Y-m-d H:i');
    }

    public function testNextAndLatestInUtc(): void
    {
        $cron = CronSchedule::of('0 3 * * *', 'UTC');

        self::assertSame('2026-10-21 03:00', self::show($cron->next(self::utc('2026-10-20 03:00'))));
        self::assertSame('2026-10-20 03:00', self::show($cron->next(self::utc('2026-10-20 02:59:59'))));
        self::assertSame('2026-10-20 03:00', self::show($cron->latest(self::utc('2026-10-20 03:00:00'))));
        self::assertSame('2026-10-19 03:00', self::show($cron->latest(self::utc('2026-10-20 02:59:59'))));
    }

    public function testOccurrencesAreReadInTheTimezone(): void
    {
        // Brussels is UTC+2 on 2026-10-20 (summer time) and UTC+1 on 2026-10-26.
        $cron = CronSchedule::of('0 3 * * *', 'Europe/Brussels');

        self::assertSame('2026-10-20 01:00', self::show($cron->next(self::utc('2026-10-19 12:00'))));
        self::assertSame('2026-10-26 02:00', self::show($cron->next(self::utc('2026-10-25 12:00'))));
    }

    public function testSkippedTimeOfSpringForwardFiresOnceAfterTheGap(): void
    {
        // 2026-03-29 02:30 does not exist in Brussels: the day fires once, at 03:30 local (01:30 UTC).
        $cron = CronSchedule::of('30 2 * * *', 'Europe/Brussels');

        self::assertSame('2026-03-29 01:30', self::show($cron->next(self::utc('2026-03-28 12:00'))));
        self::assertSame('2026-03-30 00:30', self::show($cron->next(self::utc('2026-03-29 01:30'))));
        self::assertSame('2026-03-29 01:30', self::show($cron->latest(self::utc('2026-03-29 03:00'))));
    }

    public function testRepeatedTimeOfFallBackFiresOnceWhenHoursAreFixed(): void
    {
        // 2026-10-25 02:30 happens twice in Brussels (00:30 UTC and 01:30 UTC): only the first pass fires.
        $cron = CronSchedule::of('30 2 * * *', 'Europe/Brussels');

        self::assertSame('2026-10-25 00:30', self::show($cron->next(self::utc('2026-10-24 12:00'))));
        self::assertSame('2026-10-26 01:30', self::show($cron->next(self::utc('2026-10-25 00:30'))));
        self::assertSame('2026-10-25 00:30', self::show($cron->latest(self::utc('2026-10-25 02:00'))));
    }

    public function testHourlyScheduleKeepsFiringThroughTheRepeatedHour(): void
    {
        $cron = CronSchedule::of('0 * * * *', 'Europe/Brussels');
        $at = self::utc('2026-10-24 22:30');
        $fired = [];
        for ($i = 0; $i < 5; ++$i) {
            $at = $cron->next($at);
            $fired[] = self::show($at);
        }

        self::assertSame(['2026-10-24 23:00', '2026-10-25 00:00', '2026-10-25 01:00', '2026-10-25 02:00', '2026-10-25 03:00'], $fired);
    }

    public function testNoLatestOccurrenceIsNull(): void
    {
        self::assertNull(CronSchedule::of('0 0 31 2 *', 'UTC')->latest(self::utc('2026-10-20 12:00')));
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('invalid')]
    public function testErrorsNameTheField(mixed $cron, mixed $timezone, array $fields): void
    {
        self::assertSame($fields, array_keys(CronSchedule::errors($cron, $timezone)));
    }

    /**
     * @return iterable<string, array{mixed, mixed, list<string>}>
     */
    public static function invalid(): iterable
    {
        yield 'valid' => ['*/5 * * * *', 'Europe/Brussels', []];
        yield 'four fields' => ['* * * *', 'UTC', ['cron']];
        yield 'six fields' => ['0 0 3 * * *', 'UTC', ['cron']];
        yield 'out of range' => ['61 * * * *', 'UTC', ['cron']];
        yield 'macro' => ['@daily', 'UTC', ['cron']];
        yield 'empty' => ['', 'UTC', ['cron']];
        yield 'not a string' => [null, 'UTC', ['cron']];
        yield 'unknown timezone' => ['0 3 * * *', 'Mars/Base', ['timezone']];
        yield 'empty timezone' => ['0 3 * * *', '', ['timezone']];
        yield 'both' => ['x', 'y', ['cron', 'timezone']];
    }
}
