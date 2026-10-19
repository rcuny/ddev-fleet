<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Job\Job;
use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\Schedule;
use App\Fleet\Jobs\JobService;
use App\Fleet\Projects\Refused;
use App\Fleet\Schedules\ScheduleAction;

/**
 * A schedule action that queues a job of the fake type "fake-nightly" through the real JobService, and can be told to refuse.
 */
final class FakeScheduleAction implements ScheduleAction
{
    public const string TYPE = 'fake-nightly';

    public int $started = 0;
    public ?string $refuse = null;

    public function __construct(private readonly JobService $jobs, private readonly string $name = 'fake')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function targetError(?string $instanceId): ?string
    {
        return 'gone' === $instanceId ? 'This instance does not exist.' : null;
    }

    public function start(Schedule $schedule, JobTrigger $trigger, string $requestedBy): ?int
    {
        if (null !== $this->refuse) {
            throw new Refused($this->refuse);
        }
        ++$this->started;
        $job = new Job(self::TYPE, $schedule->instanceId(), null, $trigger, $requestedBy, [], null, false, null, new \DateTimeImmutable());
        $this->jobs->enqueue($job);

        return $job->id();
    }
}
