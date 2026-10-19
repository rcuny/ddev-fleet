<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ScheduleRunRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Claims an occurrence for the ticker: inserts its run row unless one exists. The unique (schedule, claim) decides
     * between two ticks racing; a plain INSERT OR IGNORE keeps the entity manager open for the loser.
     *
     * @return ScheduleRun|null the new run, or null when the occurrence was already claimed
     */
    public function claim(Schedule $schedule, \DateTimeImmutable $occurrence, ScheduleTrigger $trigger, \DateTimeImmutable $now): ?ScheduleRun
    {
        $type = Type::getType(UtcDateTimeImmutableType::NAME);
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $at = $type->convertToDatabaseValue($occurrence, $platform);

        $inserted = $this->em->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO schedule_run (schedule_id, occurrence, claim, trigger_kind, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$schedule->id(), $at, $at, $trigger->value, ScheduleOutcome::Claimed->value, $type->convertToDatabaseValue($now, $platform)],
        );
        if (0 === $inserted) {
            return null;
        }

        return $this->em->getRepository(ScheduleRun::class)->find((int) $this->em->getConnection()->lastInsertId());
    }

    /**
     * Runs left `claimed` with no job since before the given moment: the ticker died between the claim and the launch.
     *
     * @return list<ScheduleRun> oldest first
     */
    public function orphans(\DateTimeImmutable $before): array
    {
        return $this->em->getRepository(ScheduleRun::class)->createQueryBuilder('r')
            ->where('r.outcome = :claimed')->andWhere('r.jobId IS NULL')->andWhere('r.createdAt < :before')
            ->setParameter('claimed', ScheduleOutcome::Claimed)
            ->setParameter('before', $before, UtcDateTimeImmutableType::NAME)
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function find(int $id): ?ScheduleRun
    {
        return $this->em->getRepository(ScheduleRun::class)->find($id);
    }

    public function save(ScheduleRun $run): void
    {
        $this->em->persist($run);
        $this->em->flush();
    }

    /**
     * @return list<ScheduleRun> newest first
     */
    public function latest(Schedule $schedule, int $limit): array
    {
        return $this->em->getRepository(ScheduleRun::class)->findBy(['schedule' => $schedule], ['id' => 'DESC'], $limit);
    }

    public function lastOf(Schedule $schedule): ?ScheduleRun
    {
        return $this->latest($schedule, 1)[0] ?? null;
    }
}
