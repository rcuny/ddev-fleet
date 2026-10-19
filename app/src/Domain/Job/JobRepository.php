<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class JobRepository
{
    private const int CHUNK = 500;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?Job
    {
        return $this->em->getRepository(Job::class)->find($id);
    }

    public function add(Job $job): void
    {
        $this->em->persist($job);
        $this->em->flush();
    }

    public function save(Job $job): void
    {
        $this->em->persist($job);
        $this->em->flush();
    }

    /**
     * Takes a queued job: one UPDATE that only matches while the status is still `queued`, so of several workers
     * racing for the same job exactly one gets true. A managed copy of the job is refreshed on success.
     */
    public function claim(int $id, \DateTimeImmutable $at): bool
    {
        $claimed = 1 === $this->em->getConnection()->executeStatement(
            'UPDATE job SET status = :running, started_at = :at WHERE id = :id AND status = :queued',
            ['running' => JobStatus::Running->value, 'at' => $at, 'id' => $id, 'queued' => JobStatus::Queued->value],
            ['at' => UtcDateTimeImmutableType::NAME],
        );

        if ($claimed) {
            $job = $this->em->getUnitOfWork()->tryGetById($id, Job::class);
            if ($job instanceof Job) {
                $this->em->refresh($job);
            }
        }

        return $claimed;
    }

    /**
     * Whether an earlier job (lower id) of the same batch is still queued or running.
     */
    public function hasEarlierActiveInBatch(Job $job): bool
    {
        $batch = $job->batchId();
        if (null === $batch || null === $job->id()) {
            return false;
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM job WHERE batch_id = :batch AND id < :id AND status IN (:queued, :running)',
            ['batch' => $batch, 'id' => $job->id(), 'queued' => JobStatus::Queued->value, 'running' => JobStatus::Running->value],
        ) > 0;
    }

    /**
     * Which of the given instances have a queued or running job.
     *
     * @param list<string> $instanceIds
     *
     * @return list<string> ordered
     */
    public function activeInstanceIds(array $instanceIds): array
    {
        if ([] === $instanceIds) {
            return [];
        }

        /** @var list<string> $busy */
        $busy = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT instance_id FROM job WHERE instance_id IN (:ids) AND status IN (:queued, :running) ORDER BY instance_id ASC',
            ['ids' => $instanceIds, 'queued' => JobStatus::Queued->value, 'running' => JobStatus::Running->value],
            ['ids' => ArrayParameterType::STRING],
        );

        return $busy;
    }

    /**
     * @return list<Job> ordered by id
     */
    public function running(): array
    {
        return $this->em->getRepository(Job::class)->findBy(['status' => JobStatus::Running], ['id' => 'ASC']);
    }

    /**
     * @return list<Job> newest first
     */
    public function forInstance(string $id, int $limit = 50): array
    {
        return $this->em->getRepository(Job::class)->findBy(['instanceId' => $id], ['id' => 'DESC'], $limit);
    }

    /**
     * The newest job of each given instance, in one query.
     *
     * @param list<string> $ids
     *
     * @return array<string, Job> instance id => job; an instance without jobs is absent
     */
    public function latestPerInstance(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Job> $jobs */
        $jobs = $this->em->createQuery(
            \sprintf(
                'SELECT j FROM %1$s j WHERE j.id IN (SELECT MAX(j2.id) FROM %1$s j2 WHERE j2.instanceId IN (:ids) GROUP BY j2.instanceId)',
                Job::class,
            ),
        )->setParameter('ids', $ids)->getResult();

        $latest = [];
        foreach ($jobs as $job) {
            if (null !== $job->instanceId()) {
                $latest[$job->instanceId()] = $job;
            }
        }

        return $latest;
    }

    /**
     * @param array{status?: ?JobStatus, trigger?: ?JobTrigger, type?: ?string, project?: ?string, instance?: ?string, q?: ?string} $filters
     *
     * @return array{rows: list<Job>, total: int} newest first; `q` matches the job number, the instance or the project
     */
    public function search(array $filters, int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $where = [];
        $params = [];
        if (null !== ($filters['status'] ?? null)) {
            $where[] = 'j.status = :status';
            $params['status'] = $filters['status'];
        }
        if (null !== ($filters['trigger'] ?? null)) {
            $where[] = 'j.trigger = :trigger';
            $params['trigger'] = $filters['trigger'];
        }
        if (null !== ($filters['type'] ?? null)) {
            $where[] = 'j.type = :type';
            $params['type'] = $filters['type'];
        }
        if (null !== ($filters['project'] ?? null)) {
            $where[] = 'j.projectName = :project';
            $params['project'] = $filters['project'];
        }
        if (null !== ($filters['instance'] ?? null)) {
            $where[] = 'j.instanceId = :instance';
            $params['instance'] = $filters['instance'];
        }
        $q = trim($filters['q'] ?? '');
        if ('' !== $q) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $clause = "j.instanceId LIKE :like ESCAPE '!' OR j.projectName LIKE :like ESCAPE '!'";
            if (ctype_digit($q)) {
                $clause .= ' OR j.id = :qid';
                $params['qid'] = (int) $q;
            }
            $where[] = '('.$clause.')';
            $params['like'] = $like;
        }
        $condition = [] === $where ? '' : ' WHERE '.implode(' AND ', $where);

        $total = (int) $this->em->createQuery(\sprintf('SELECT COUNT(j.id) FROM %s j%s', Job::class, $condition))
            ->setParameters($params)->getSingleScalarResult();

        /** @var list<Job> $rows */
        $rows = $this->em->createQuery(\sprintf('SELECT j FROM %s j%s ORDER BY j.id DESC', Job::class, $condition))
            ->setParameters($params)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Deletes the finished jobs that ended before $before; queued and running jobs are never touched.
     *
     * @return list<int> the ids that were deleted
     */
    public function pruneFinishedBefore(\DateTimeImmutable $before): array
    {
        $connection = $this->em->getConnection();
        $finals = [JobStatus::Succeeded->value, JobStatus::Failed->value, JobStatus::Interrupted->value];

        /** @var list<int|string> $found */
        $found = $connection->fetchFirstColumn(
            'SELECT id FROM job WHERE status IN (:finals) AND finished_at < :before ORDER BY id ASC',
            ['finals' => $finals, 'before' => $before],
            ['finals' => ArrayParameterType::STRING, 'before' => UtcDateTimeImmutableType::NAME],
        );
        $ids = array_map('intval', $found);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $connection->executeStatement('DELETE FROM job WHERE id IN (:ids)', ['ids' => $chunk], ['ids' => ArrayParameterType::INTEGER]);
        }
        $unitOfWork = $this->em->getUnitOfWork();
        foreach ($ids as $id) {
            $managed = $unitOfWork->tryGetById($id, Job::class);
            if ($managed instanceof Job) {
                $this->em->detach($managed);
            }
        }

        return $ids;
    }
}
