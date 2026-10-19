<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Finds jobs marked `running` whose worker died. A running job always holds its instance lock (JobExecutor locks before
 * it claims), so a free lock means nobody is working on it: the job is `interrupted`. There is no heartbeat.
 *
 * It also fails stale `queued` jobs. A live message re-queues itself and gives up 30 minutes after the job became
 * eligible (JobExecutor::GIVE_UP_SECONDS), so a job still queued well past that has lost its message (an exception
 * before the dispatch, or the message sits in the `failed` transport) and would block its instance for ever.
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

    /** How long past the give-up limit a queued job may wait before it counts as orphaned. */
    public const int ORPHAN_GRACE_SECONDS = 600;

    /**
     * @return int the number of jobs marked interrupted or failed
     */
    public function reap(): int
    {
        return $this->reapRunning() + $this->failOrphanedQueued();
    }

    private function failOrphanedQueued(): int
    {
        $now = $this->clock->now();
        $limit = JobExecutor::GIVE_UP_SECONDS + self::ORPHAN_GRACE_SECONDS;
        $failed = 0;
        foreach ($this->jobs->queuedCreatedBefore($now->modify(\sprintf('-%d seconds', $limit))) as $job) {
            // A member that still waits for an active predecessor is not orphaned: the predecessor is judged on its own.
            if ($job->isSequential() && $this->jobs->hasEarlierActiveInBatch($job)) {
                continue;
            }
            if ($now->getTimestamp() - $this->jobs->eligibleSince($job)->getTimestamp() < $limit) {
                continue;
            }
            // The executor may have claimed or failed it meanwhile: judge the row as it is now.
            $this->jobs->refresh($job);
            if (JobStatus::Queued !== $job->status()) {
                continue;
            }
            $this->service->finish($job, JobStatus::Failed, null, 'The job was never started: its queue message was lost.');
            $this->settleInstance($job, started: false);
            ++$failed;
        }

        return $failed;
    }

    private function settleInstance(Job $job, bool $started = true): void
    {
        $instance = null === $job->instanceId() ? null : $this->instances->find($job->instanceId());
        if (null === $instance) {
            return;
        }
        $transitional = \in_array($instance->state(), [InstanceState::Deploying, InstanceState::Destroying], true);
        if (!$started && !$transitional) {
            return; // a start or stop that never began changed nothing
        }
        $instance->changeState($transitional ? InstanceState::Failed : InstanceState::Unknown, $this->clock->now());
        $this->instances->save($instance);
    }

    private function reapRunning(): int
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
                // The executor may have finished the job between running() and the lock: judge the row as it is now.
                $this->jobs->refresh($job);
                if (JobStatus::Running !== $job->status()) {
                    continue;
                }
                $this->service->finish($job, JobStatus::Interrupted, null, 'The worker stopped while the job was running.');
                $this->settleInstance($job);
                ++$reaped;
            } finally {
                $lock->release();
            }
        }

        return $reaped;
    }
}
