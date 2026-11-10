<?php

declare(strict_types=1);

namespace App\Fleet\Host\Drift;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Jobs\JobService;
use App\Fleet\Projects\Refused;
use Psr\Clock\ClockInterface;

/** Queues "run the drift check" as a job (FLE-261): a request never runs the playbook. */
final readonly class DriftService
{
    public const string JOB_TYPE = 'drift';

    public function __construct(
        private JobService $jobs,
        private JobRepository $repository,
        private DriftCheck $check,
        private DriftReportStore $store,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    public function unavailable(): ?string
    {
        return $this->check->unavailable();
    }

    public function activeJob(): ?Job
    {
        return $this->repository->activeOfType(self::JOB_TYPE);
    }

    public function latestJob(): ?Job
    {
        return $this->repository->latestOfType(self::JOB_TYPE);
    }

    public function lastReport(): ?DriftReport
    {
        return $this->store->last();
    }

    /**
     * @throws Refused when a check is already queued or running, or this host cannot run one
     */
    public function queue(string $actor): Job
    {
        if (null !== $reason = $this->unavailable()) {
            throw new Refused($reason);
        }
        if (null !== $this->activeJob()) {
            throw new Refused('A drift check is already queued or running. Wait for it to finish.');
        }
        $job = new Job(self::JOB_TYPE, null, null, JobTrigger::Ui, $actor, [], now: $this->clock->now());
        $this->jobs->enqueue($job);
        $this->audit->record($actor, AuditLog::HOST_DRIFT, 'drift check queued');

        return $job;
    }
}
