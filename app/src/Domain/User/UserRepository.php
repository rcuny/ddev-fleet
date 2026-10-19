<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

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
     * Every user as stored right now: entities this process already holds are refreshed from the database, so a
     * change committed by another process is not missed. Unflushed changes to those entities are overwritten.
     *
     * @return list<User>
     */
    public function allFresh(): array
    {
        /** @var list<User> $users */
        $users = $this->em
            ->createQuery(\sprintf('SELECT u FROM %s u ORDER BY u.username ASC', User::class))
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $users;
    }

    /**
     * Runs $work in one database transaction: committed when it returns, rolled back (and the entity manager
     * cleared, so nothing half-changed stays in memory) when it throws. The entity manager stays usable.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->em->clear();

            throw $e;
        }
    }

    /**
     * Enabled members of the group only (the administrators, for the admin group): a disabled user cannot sign in, so
     * it does not count.
     */
    public function countInGroup(string $group): int
    {
        return \count(array_filter(
            $this->em->getRepository(User::class)->findBy(['enabled' => true]),
            static fn (User $user): bool => $user->isInGroup($group),
        ));
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }

    /**
     * Deletes the user; its project_access rows go with it (foreign key cascade).
     */
    public function remove(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }

    /**
     * Writes every pending change of the entity manager (needed before a read that refreshes entities, see allFresh()).
     */
    public function flush(): void
    {
        $this->em->flush();
    }
}
