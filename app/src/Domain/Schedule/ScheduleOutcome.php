<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

/**
 * What the ticker (or run now) did with an occurrence. The job's own status tells how the work ended.
 */
enum ScheduleOutcome: string
{
    /** A job was queued; follow its status. */
    case Claimed = 'claimed';
    /** The action refused to start (the instance is busy, for example): no job. */
    case Skipped = 'skipped';
    /** The occurrence was older than the catch-up window: nothing fired. */
    case Missed = 'missed';
    /** The action could not be started (the target is gone, the queue refused). */
    case Failed = 'failed';
}
