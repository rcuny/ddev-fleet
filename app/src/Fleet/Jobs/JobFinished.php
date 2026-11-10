<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;

/** Dispatched by JobService::finish() once the job's final status is stored (and its instance is no longer busy). */
final readonly class JobFinished
{
    public function __construct(public Job $job)
    {
    }
}
