<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Finds jobs marked `running` whose worker died. A running job always holds its instance lock (JobExecutor locks before
 * it claims), so a free lock means nobody is working on it: the job is `interrupted`. There is no heartbeat.
 */
final readonly class JobReaper
{
    public function __construct(
        private JobRepository $jobs,
        private InstanceRepository $instances,
        private JobService $service,
        private LockFactory $locks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the number of jobs marked interrupted
     */
    public function reap(): int
    {
        $reaped = 0;
        foreach ($this->jobs->running() as $job) {
            $instanceId = $job->instanceId();
            if (null === $instanceId) {
                continue; // no lock to judge it by (no job type works without an instance yet)
            }

            $lock = $this->locks->createLock('instance.'.$instanceId);
            if (!$lock->acquire(false)) {
                continue;
            }
            try {
                $this->service->finish($job, JobStatus::Interrupted, null, 'The worker stopped while the job was running.');
                $instance = $this->instances->find($instanceId);
                if (null !== $instance) {
                    $instance->changeState(
                        \in_array($instance->state(), [InstanceState::Deploying, InstanceState::Destroying], true)
                            ? InstanceState::Failed
                            : InstanceState::Unknown,
                        $this->clock->now(),
                    );
                    $this->instances->save($instance);
                }
                ++$reaped;
            } finally {
                $lock->release();
            }
        }

        return $reaped;
    }
}
