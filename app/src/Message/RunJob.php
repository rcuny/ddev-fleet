<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks a worker to run one job. Only the id travels: the job row is the state, so a redelivered message finds a job
 * that is no longer queued and does nothing.
 */
final readonly class RunJob
{
    public function __construct(public int $jobId)
    {
    }
}
