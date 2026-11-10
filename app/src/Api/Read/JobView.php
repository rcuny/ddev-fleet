<?php

declare(strict_types=1);

namespace App\Api\Read;

use App\Domain\Job\Job;

/** A job as the API shows it. Never the params (they can hold values a person typed). */
final class JobView
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Job $job): array
    {
        return [
            'id' => $job->id(),
            'type' => $job->type(),
            'status' => $job->status()->value,
            'final' => $job->status()->isFinal(),
            'trigger' => $job->trigger()->value,
            'requested_by' => $job->requestedBy(),
            'project' => $job->projectName(),
            'instance' => $job->instanceId(),
            'batch_id' => $job->batchId(),
            'retry_of' => $job->retryOf(),
            'exit_code' => $job->exitCode(),
            'error' => $job->error(),
            'created_at' => $job->createdAt()->format(\DATE_ATOM),
            'started_at' => $job->startedAt()?->format(\DATE_ATOM),
            'finished_at' => $job->finishedAt()?->format(\DATE_ATOM),
        ];
    }
}
