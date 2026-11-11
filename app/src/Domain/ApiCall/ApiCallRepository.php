<?php

declare(strict_types=1);

namespace App\Domain\ApiCall;

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
}
