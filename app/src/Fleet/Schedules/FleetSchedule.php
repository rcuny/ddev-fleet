<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use App\Message\TickSchedules;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The scheduler transport `scheduler_default`: one recurring message every 30 seconds. What is due, and the catch-up
 * after downtime, is decided from the database by ScheduleTicker, so this schedule is deliberately not stateful.
 */
#[AsSchedule]
final class FleetSchedule implements ScheduleProviderInterface
{
    public const int TICK_SECONDS = 30;

    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every(self::TICK_SECONDS.' seconds', new TickSchedules()));
    }
}
