<?php

declare(strict_types=1);

namespace App\Domain\ApiCall;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApiCallRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(ApiCall $call): void
    {
        $this->em->persist($call);
        $this->em->flush();
    }

    /**
     * @return list<ApiCall> the newest first
     */
    public function latestForToken(int $tokenId, int $limit = 20): array
    {
        return $this->em->getRepository(ApiCall::class)->findBy(['tokenId' => $tokenId], ['id' => 'DESC'], $limit);
    }

    /**
     * @return list<ApiCall> the newest first
     */
    public function latest(int $limit = 50): array
    {
        return $this->em->getRepository(ApiCall::class)->findBy([], ['id' => 'DESC'], $limit);
    }

    /** How many terminal calls were recorded at or after $since. */
    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM api_call WHERE created_at >= :since',
            ['since' => $since],
            ['since' => UtcDateTimeImmutableType::NAME],
        );
    }

    /** Deletes the evidence rows older than $before (the retention); returns how many. */
    public function pruneBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'DELETE FROM api_call WHERE created_at < :before',
            ['before' => $before],
            ['before' => UtcDateTimeImmutableType::NAME],
        );
    }
}
