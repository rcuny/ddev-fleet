<?php

declare(strict_types=1);

namespace App\Domain\ApiToken;

use Doctrine\ORM\EntityManagerInterface;

final readonly class ApiTokenRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(int $id): ?ApiToken
    {
        return $this->em->getRepository(ApiToken::class)->find($id);
    }

    public function findByHash(string $hash): ?ApiToken
    {
        return $this->em->getRepository(ApiToken::class)->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * @return list<ApiToken> newest first
     */
    public function all(): array
    {
        return $this->em->getRepository(ApiToken::class)->findBy([], ['id' => 'DESC']);
    }

    public function save(ApiToken $token): void
    {
        $this->em->persist($token);
        $this->em->flush();
    }
}
