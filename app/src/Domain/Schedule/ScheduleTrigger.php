<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

/**
 * Why a run exists: the cron fired on time, the ticker was down and caught the latest occurrence up, or a person pressed run now.
 */
enum ScheduleTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case CatchUp = 'catch-up';
}
