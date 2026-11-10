<?php

declare(strict_types=1);

namespace App\Domain\Ssh;

use Doctrine\ORM\EntityManagerInterface;

final readonly class ManagedSshKeyRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByName(string $name): ?ManagedSshKey
    {
        return $this->em->getRepository(ManagedSshKey::class)->findOneBy(['name' => $name]);
    }

    /**
     * @return list<ManagedSshKey> ordered by name
     */
    public function all(): array
    {
        return $this->em->getRepository(ManagedSshKey::class)->findBy([], ['name' => 'ASC']);
    }

    public function save(ManagedSshKey $key): void
    {
        $this->em->persist($key);
        $this->em->flush();
    }

    public function remove(ManagedSshKey $key): void
    {
        $this->em->remove($key);
        $this->em->flush();
    }
}
