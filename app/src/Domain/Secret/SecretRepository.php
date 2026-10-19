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

    /**
     * @return list<Secret> ordered by name
     */
    public function listFor(string $scope, string $scopeId): array
    {
        return $this->em->getRepository(Secret::class)->findBy(
            ['scope' => $scope, 'scopeId' => $scopeId],
            ['name' => 'ASC'],
        );
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

    /**
     * Runs $work in one database transaction (the entity manager is closed if it throws).
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function transactional(\Closure $work): mixed
    {
        return $this->em->wrapInTransaction($work);
    }
}
