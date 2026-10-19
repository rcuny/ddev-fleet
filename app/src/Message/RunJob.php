<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks a worker to run one job. Only the id travels: the job row is the state, so a redelivered message finds a job
 * that is no longer queued and does nothing.
 *
 * $eligibleSince (a Unix timestamp) is the one exception: a re-queued message carries the moment its job became eligible
 * (JobRepository::eligibleSince), so the give-up clock is read from the database once, not at every busy poll.
 */
final readonly class RunJob
{
    public function __construct(public int $jobId, public ?int $eligibleSince = null)
    {
    }
}
