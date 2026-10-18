<?php

declare(strict_types=1);

namespace App\Domain\Secret;

use Doctrine\ORM\EntityManagerInterface;

final readonly class SecretRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $scope, string $scopeId, string $name): ?Secret
    {
        return $this->em->getRepository(Secret::class)->findOneBy([
            'scope' => $scope,
            'scopeId' => $scopeId,
            'name' => $name,
        ]);
    }

    public function save(Secret $secret): void
    {
        $this->em->persist($secret);
        $this->em->flush();
    }

    public function remove(Secret $secret): void
    {
        $this->em->remove($secret);
        $this->em->flush();
    }
}
