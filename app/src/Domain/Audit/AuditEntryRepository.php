<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Doctrine\ORM\EntityManagerInterface;

final readonly class AuditEntryRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(AuditEntry $entry): void
    {
        $this->em->persist($entry);
        $this->em->flush();
    }

    /**
     * The newest entries first.
     *
     * @return list<AuditEntry>
     */
    public function latest(int $limit, ?string $action = null): array
    {
        $criteria = null === $action ? [] : ['action' => $action];

        return $this->em->getRepository(AuditEntry::class)->findBy($criteria, ['id' => 'DESC'], $limit);
    }

    /**
     * @return list<string> every action that has an entry, alphabetically
     */
    public function actions(): array
    {
        /** @var list<array{action: string}> $rows */
        $rows = $this->em->createQuery('SELECT DISTINCT e.action FROM '.AuditEntry::class.' e ORDER BY e.action ASC')->getArrayResult();

        return array_map(static fn (array $row): string => $row['action'], $rows);
    }

    /** Whether an entry of $action whose subject starts with $subjectPrefix was recorded at or after $since. */
    public function existsSince(string $action, string $subjectPrefix, \DateTimeImmutable $since): bool
    {
        $count = $this->em->createQuery('SELECT COUNT(e.id) FROM '.AuditEntry::class.' e WHERE e.action = :action AND e.createdAt >= :since AND e.subject LIKE :prefix ESCAPE \'!\'')
            ->setParameter('action', $action)
            ->setParameter('since', $since, \App\Doctrine\UtcDateTimeImmutableType::NAME)
            ->setParameter('prefix', str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $subjectPrefix).'%')
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
