<?php

declare(strict_types=1);

namespace App\Domain\Ssh;

use Doctrine\ORM\EntityManagerInterface;

final readonly class SshKeyRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByRole(SshKeyRole $role): ?SshKey
    {
        return $this->em->getRepository(SshKey::class)->findOneBy(['role' => $role]);
    }

    public function save(SshKey $key): void
    {
        $this->em->persist($key);
        $this->em->flush();
    }
}
