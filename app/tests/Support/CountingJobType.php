<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Job\Job;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;

/**
 * A job type whose behaviour is a closure; counts its runs.
 */
final class CountingJobType implements JobType
{
    public int $runs = 0;

    /**
     * @param \Closure(Job, JobLog): void $body
     */
    public function __construct(private readonly string $name, private readonly \Closure $body)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function execute(Job $job, JobLog $log): void
    {
        ++$this->runs;
        ($this->body)($job, $log);
    }
}
