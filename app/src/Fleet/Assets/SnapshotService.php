<?php

declare(strict_types=1);

namespace App\Fleet\Assets;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Jobs\JobService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Asks for a snapshot of a running instance's database: validates the instance, refuses one that already has a queued or
 * running job, then queues a `snapshot` job (SnapshotJob does the work in a worker) and records who asked. It never runs
 * a process. A request that names a project only reaches that project's instances.
 */
final readonly class SnapshotService
{
    public function __construct(
        private InstanceRepository $instances,
        private JobRepository $jobs,
        private JobService $jobService,
        private EntityManagerInterface $em,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The ids of the project's running instances, in order.
     *
     * @return list<string>
     */
    public function snapshottable(Project $project): array
    {
        $ids = array_map(
            static fn (Instance $i): string => $i->id(),
            array_filter($this->instances->byProject($project), static fn (Instance $i): bool => InstanceState::Running === $i->state()),
        );
        sort($ids);

        return $ids;
    }

    /**
     * @param string|null $project the project the caller is working in: an instance of another one does not exist for them
     *
     * @throws NotFound
     * @throws Refused  the instance is not running, or has a queued or running job
     */
    public function request(string $instanceId, JobTrigger $trigger, string $by, ?string $project = null): Job
    {
        $instance = InstanceNaming::isInstanceId($instanceId) ? $this->instances->find($instanceId) : null;
        if (null === $instance || (null !== $project && $instance->project()->name() !== $project)) {
            throw NotFound::instance($instanceId);
        }
        if (InstanceState::Running !== $instance->state()) {
            throw new Refused(\sprintf('Instance %s is not running (%s): only a running instance can be snapshotted.', $instance->id(), $instance->state()->value));
        }
        if ([] !== $this->jobs->activeInstanceIds([$instance->id()])) {
            throw new Refused(\sprintf('Busy: %s has a job queued or running. Nothing was started.', $instance->id()));
        }

        $job = new Job(SnapshotJob::NAME, $instance->id(), $instance->project()->name(), $trigger, $by, [], null, false, null, $this->clock->now());
        $this->em->persist($job);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new Refused('Busy: another request just queued a job for this instance. Nothing was started.');
        }
        $this->audit->record($by, AuditLog::ASSET_SNAPSHOT, $instance->id());
        $this->jobService->enqueue($job);

        return $job;
    }
}
