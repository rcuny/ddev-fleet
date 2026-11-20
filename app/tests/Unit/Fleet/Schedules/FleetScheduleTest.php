<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Schedules;

use App\Fleet\Schedules\FleetSchedule;
use App\Message\TickSchedules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class FleetScheduleTest extends TestCase
{
    public function testOneRecurringTickEvery30Seconds(): void
    {
        $messages = new FleetSchedule()->getSchedule()->getRecurringMessages();

        self::assertCount(2, $messages);
        $trigger = $messages[0]->getTrigger();
        self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $from = new \DateTimeImmutable('2026-10-21 03:00:00 UTC');
        self::assertSame(30, $trigger->getNextRunDate($from)?->getTimestamp() - $from->getTimestamp());
        self::assertInstanceOf(TickSchedules::class, iterator_to_array($messages[0]->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('default', 'x', $trigger, $from)))[0] ?? null);
    }

    public function testDailyCiRetentionPass(): void
    {
        $messages = new FleetSchedule()->getSchedule()->getRecurringMessages();
        $trigger = $messages[1]->getTrigger();
        $from = new \DateTimeImmutable('2026-10-21 03:18:00 UTC');
        $next = $trigger->getNextRunDate($from);

        self::assertNotNull($next);
        self::assertSame(86400 - 60, $next->getTimestamp() - $from->getTimestamp());
        self::assertInstanceOf(\App\Message\RunInstanceRetention::class, iterator_to_array($messages[1]->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('default', 'x', $trigger, $from)))[0] ?? null);
    }
}
