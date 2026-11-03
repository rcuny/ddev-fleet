<?php

declare(strict_types=1);

namespace App\Fleet\Workspace;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Host\HostSecrets;
use App\Fleet\Jobs\JobService;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use App\Fleet\Secrets\SecretService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Hands the stored Claude token to running instances, on request only. Each instance gets one `restart` job (the job
 * rewrites its `config.fleet.yaml` and runs `ddev restart`), all in one sequential batch so the host restarts one at a
 * time. Nothing here is automatic: the restart ends whatever runs in the containers, a Claude agent included.
 * The token is not passed on: the job reads it from the secret store when it runs.
 */
final readonly class ClaudeTokenApplier
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
     * @param list<string>|null $instanceIds the instances to restart, or null for every running instance
     *
     * @return list<Job> in the order they run
     *
     * @throws NotFound when an id names no instance
     * @throws Refused  when an instance is not running, or has a job queued or running (nothing is queued then)
     */
    public function apply(?array $instanceIds, JobTrigger $trigger, string $by): array
    {
        $selected = [];
        if (null === $instanceIds) {
            foreach ($this->instances->all() as $instance) {
                if (InstanceState::Running === $instance->state()) {
                    $selected[$instance->id()] = $instance;
                }
            }
        } else {
            foreach (array_unique($instanceIds) as $id) {
                $instance = $this->instances->find($id) ?? throw NotFound::instance($id);
                if (InstanceState::Running !== $instance->state()) {
                    throw new Refused(\sprintf('%s is not running, so there is nothing to restart. Nothing was queued.', $id));
                }
                $selected[$id] = $instance;
            }
        }
        if ([] === $selected) {
            return [];
        }
        ksort($selected);

        $busy = $this->jobs->activeInstanceIds(array_keys($selected));
        if ([] !== $busy) {
            throw new Refused(\sprintf('Busy: %s %s a job queued or running. Nothing was queued.', implode(', ', \array_slice($busy, 0, 5)).(\count($busy) > 5 ? ' and more' : ''), 1 === \count($busy) ? 'has' : 'have'));
        }

        $batch = \count($selected) > 1 ? bin2hex(random_bytes(8)) : null;
        $now = $this->clock->now();
        $jobs = [];
        foreach ($selected as $instance) {
            $job = new Job('restart', $instance->id(), $instance->project()->name(), $trigger, $by, [], $batch, null !== $batch, null, $now);
            $this->em->persist($job);
            $jobs[] = $job;
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new Refused('Busy: another request just queued a job for one of these instances. Nothing was queued.');
        }
        $this->audit->record($by, AuditLog::CLAUDE_TOKEN_APPLY, SecretService::subject(null, HostSecrets::CLAUDE_TOKEN));
        foreach ($jobs as $job) {
            $this->jobService->enqueue($job);
        }

        return $jobs;
    }
}
