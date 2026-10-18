<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\ORM\EntityManagerInterface;

final readonly class UserRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByUsername(string $username): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return $this->em->getRepository(User::class)->findBy([], ['username' => 'ASC']);
    }

    /**
     * Enabled administrators only: a disabled admin cannot sign in, so it does not count as one.
     */
    public function countAdmins(): int
    {
        return \count(array_filter(
            $this->em->getRepository(User::class)->findBy(['enabled' => true]),
            static fn (User $user): bool => $user->isAdmin(),
        ));
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }
}
