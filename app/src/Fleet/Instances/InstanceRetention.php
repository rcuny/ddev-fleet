<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use Psr\Clock\ClockInterface;

/**
 * The optional CI retention of a project (FLE-367): a project with `ci_retention_days` set loses its `ci-` instances
 * whose last deploy (else creation) is older than that many days. Selection is conservative and the destroy goes
 * through InstanceService, so the protection lock applies to it like to any other destroy.
 *
 * Never selected: a `main` instance, a label without the `ci-` prefix, a protected instance, one with a job queued or
 * running, one already being destroyed.
 */
final readonly class InstanceRetention
{
    public const string LABEL_PREFIX = 'ci-';
    public const string ACTOR = 'scheduler';

    public function __construct(
        private ProjectRepository $projects,
        private InstanceRepository $instances,
        private JobRepository $jobs,
        private InstanceService $service,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The instances the pass would destroy now, without touching anything.
     *
     * @param string|null $projectName only this project (it must exist), else every project with a retention
     *
     * @return list<array{instance: Instance, days: int, since: \DateTimeImmutable}> oldest first
     *
     * @throws NotFound
     */
    public function candidates(?string $projectName = null): array
    {
        $projects = null === $projectName ? $this->projects->all() : [$this->projects->findByName($projectName) ?? throw NotFound::project($projectName)];
        $now = $this->clock->now();
        $found = [];
        foreach ($projects as $project) {
            $days = $project->ciRetentionDays();
            if (null === $days) {
                continue;
            }
            $limit = $now->modify(\sprintf('-%d days', $days));
            $eligible = array_values(array_filter($this->instances->byProject($project), static fn (Instance $i): bool => self::isCandidate($i, $limit)));
            $busy = [] === $eligible ? [] : $this->jobs->activeInstanceIds(array_map(static fn (Instance $i): string => $i->id(), $eligible));
            foreach ($eligible as $instance) {
                if (!\in_array($instance->id(), $busy, true)) {
                    $found[] = ['instance' => $instance, 'days' => $days, 'since' => self::reference($instance)];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => $a['since'] <=> $b['since']);

        return $found;
    }

    /**
     * Destroys what candidates() lists, one destroy job per instance, each audited as `instance.retention`.
     *
     * @param string $actor who is recorded in the audit trail: `scheduler`, or `cli:<user>`
     *
     * @return array{destroyed: list<string>, skipped: list<array{id: string, reason: string}>}
     *
     * @throws NotFound
     */
    public function run(?string $projectName, string $actor, JobTrigger $trigger): array
    {
        $destroyed = [];
        $skipped = [];
        foreach ($this->candidates($projectName) as $row) {
            $id = $row['instance']->id();
            try {
                $this->service->request('destroy', new Selector(ids: [$id]), new OperationOptions(), $trigger, $actor);
            } catch (Refused $refused) {
                // Protected or busy since the selection: left alone, reported, and the pass goes on.
                $skipped[] = ['id' => $id, 'reason' => $refused->getMessage()];
                continue;
            }
            $this->audit->record($actor, AuditLog::INSTANCE_RETENTION, \sprintf('%s/%dd', $id, $row['days']));
            $destroyed[] = $id;
        }

        return ['destroyed' => $destroyed, 'skipped' => $skipped];
    }

    public static function reference(Instance $instance): \DateTimeImmutable
    {
        return $instance->deployedAt() ?? $instance->createdAt();
    }

    private static function isCandidate(Instance $instance, \DateTimeImmutable $limit): bool
    {
        return str_starts_with($instance->label(), self::LABEL_PREFIX)
            && 'main' !== $instance->label()
            && !$instance->isProtected()
            && InstanceState::Destroying !== $instance->state()
            && self::reference($instance) < $limit;
    }
}
