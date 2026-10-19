<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Fleet\FleetPaths;
use App\Message\RunJob;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Runs a job: take the instance lock, claim the job (queued -> running), execute it, always record the final status,
 * release the lock. The lock comes before the claim, so a `running` job always has a live holder (JobReaper relies on it).
 *
 * A job whose instance is busy, or whose sequential batch has an earlier unfinished job, goes back on the queue with a
 * delay and stays `queued`; after 30 minutes it fails. The 30 minutes count from when the job became eligible to run
 * (JobRepository::eligibleSince): a member of a sequential batch is not failed for waiting on its predecessors, only
 * for waiting on a busy instance once its turn has come.
 */
final class JobExecutor
{
    public const int BUSY_DELAY_MS = 3000;
    public const int GATED_DELAY_MS = 5000;
    public const int GIVE_UP_SECONDS = 1800;
    /** How deep a re-queue may nest: a `sync://` transport ignores the delay and runs the message at once. */
    private const int MAX_REQUEUE_DEPTH = 3;

    private int $requeueDepth = 0;

    public function __construct(
        private JobRepository $jobs,
        private JobTypeRegistry $types,
        private JobService $service,
        private LockFactory $locks,
        private MessageBusInterface $bus,
        private FleetPaths $paths,
        private LogMasker $masker,
        private JobFeed $feed,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $pollMicroseconds = 1_000_000,
    ) {
    }

    /**
     * The worker entry point (one RunJob message).
     */
    public function run(int $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if (null === $job || JobStatus::Queued !== $job->status()) {
            return;
        }

        if ($job->isSequential() && $this->jobs->hasEarlierActiveInBatch($job)) {
            // Waiting for a predecessor never counts against the job; every predecessor ends (or is swept as stale).
            $this->requeue($job, self::GATED_DELAY_MS);

            return;
        }

        $lock = $this->lockFor($job);
        if (!$lock->acquire(false)) {
            $this->requeueOrGiveUp($job, self::BUSY_DELAY_MS);

            return;
        }

        $this->runLocked($job, $lock);
    }

    /**
     * The CLI entry point: waits for the instance lock (up to 30 minutes) instead of re-queueing, then runs the job in
     * this process. $waiting is called each time the lock is found busy.
     *
     * @param (callable(Job): void)|null $waiting
     */
    public function runInline(int $jobId, ?callable $waiting = null): Job
    {
        $job = $this->jobs->find($jobId) ?? throw new \RuntimeException('Unknown job.');
        if (JobStatus::Queued !== $job->status()) {
            return $job;
        }

        $lock = $this->lockFor($job);
        $deadline = $this->clock->now()->getTimestamp() + self::GIVE_UP_SECONDS;
        while (!$lock->acquire(false)) {
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                $this->service->finish($job, JobStatus::Failed, null, 'Gave up waiting for the instance to be free.');

                return $job;
            }
            if (null !== $waiting) {
                $waiting($job);
            }
            usleep($this->pollMicroseconds);
        }

        $this->runLocked($job, $lock);

        return $job;
    }

    private function runLocked(Job $job, SharedLockInterface $lock): void
    {
        try {
            if (!$this->jobs->claim((int) $job->id(), $this->clock->now())) {
                return;
            }
            $this->feed->jobChanged($job, 0);
            if (null !== $job->instanceId()) {
                $this->feed->instanceChanged($job->instanceId());
            }

            $status = JobStatus::Succeeded;
            $exitCode = 0;
            $error = null;
            $log = null;
            try {
                $log = new JobLog($this->paths->jobLog((string) $job->id(), $job->instanceId()), $this->masker, $this->feed, $job);
                $type = $this->types->get($job->type()) ?? throw new JobFailed('This job type is not known.');
                $type->execute($job, $log);
            } catch (JobFailed $e) {
                $status = JobStatus::Failed;
                $exitCode = $e->exitCode;
                $error = $e->getMessage();
                $this->tryLine($log, $error);
            } catch (\Throwable $e) {
                $status = JobStatus::Failed;
                $exitCode = 1;
                $error = 'Unexpected error: see the application log.';
                $this->logger->error('Job {id} failed unexpectedly.', ['id' => $job->id(), 'exception' => $e]);
                $this->tryLine($log, $error);
            }

            $this->tryFinishLog($log);
            $this->service->finish($job, $status, $exitCode, $error);
        } finally {
            $lock->release();
        }
    }

    private function requeueOrGiveUp(Job $job, int $delayMs): void
    {
        $waited = $this->clock->now()->getTimestamp() - $this->jobs->eligibleSince($job)->getTimestamp();
        if ($waited >= self::GIVE_UP_SECONDS) {
            $this->service->finish($job, JobStatus::Failed, null, 'Gave up waiting for the instance to be free.');

            return;
        }

        $this->requeue($job, $delayMs);
    }

    private function requeue(Job $job, int $delayMs): void
    {
        if ($this->requeueDepth >= self::MAX_REQUEUE_DEPTH) {
            // Only a transport that ignores the delay (sync://, dev and test) nests this deep: waiting would recurse.
            $this->service->finish($job, JobStatus::Failed, null, 'The instance is busy and this queue cannot wait for it.');

            return;
        }

        ++$this->requeueDepth;
        try {
            $this->bus->dispatch(new RunJob((int) $job->id()), [new DelayStamp($delayMs)]);
        } finally {
            --$this->requeueDepth;
        }
    }

    private function lockFor(Job $job): SharedLockInterface
    {
        return $this->locks->createLock('instance.'.($job->instanceId() ?? 'fleet'));
    }

    private function tryFinishLog(?JobLog $log): void
    {
        try {
            $log?->finish();
        } catch (\Throwable) {
            // As above: the status carries the outcome.
        }
    }

    private function tryLine(?JobLog $log, string $message): void
    {
        try {
            $log?->line($message);
        } catch (\Throwable) {
            // The log is a courtesy at this point: the status carries the message.
        }
    }
}
