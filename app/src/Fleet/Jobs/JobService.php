<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Fleet\Projects\Refused;
use App\Message\RunJob;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates and finishes jobs. A request never runs a process: enqueue() stores the job and hands its id to the queue.
 */
final readonly class JobService
{
    public function __construct(
        private JobRepository $jobs,
        private MessageBusInterface $bus,
        private JobFeed $feed,
        private LogReader $logs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Persists the job as queued, then dispatches it. A dispatch failure fails the job instead of leaving it queued
     * for ever.
     */
    public function enqueue(Job $job): void
    {
        $this->jobs->add($job);
        $this->notify($job);

        try {
            $this->bus->dispatch(new RunJob((int) $job->id()));
        } catch (\Throwable) {
            $this->finish($job, JobStatus::Failed, null, 'The job could not be queued.');
        }
    }

    public function finish(Job $job, JobStatus $status, ?int $exitCode, ?string $error): void
    {
        $job->finish($status, $exitCode, $error, $this->clock->now());
        $this->jobs->save($job);
        $this->notify($job);
    }

    /**
     * A new job that repeats a failed one; retrying a failed deploy is a redeploy (the checkout may exist by now).
     */
    public function retry(Job $failed, string $requestedBy, JobTrigger $trigger): Job
    {
        if (JobStatus::Failed !== $failed->status() && JobStatus::Interrupted !== $failed->status()) {
            throw new Refused('Only a failed or interrupted job can be retried.');
        }

        $job = new Job(
            'deploy' === $failed->type() ? 'redeploy' : $failed->type(),
            $failed->instanceId(),
            $failed->projectName(),
            $trigger,
            $requestedBy,
            $failed->params(),
            null,
            false,
            $failed->id(),
            $this->clock->now(),
        );
        $this->enqueue($job);

        return $job;
    }

    private function notify(Job $job): void
    {
        $this->feed->jobChanged($job, $this->logs->size($job));
        if (null !== $job->instanceId()) {
            $this->feed->instanceChanged($job->instanceId());
        }
    }
}
