<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads of project memberships return plain names (scalar queries, always the stored rows, never a stale entity);
 * writes only persist or remove, the caller flushes (ProjectAccessService, inside its own transaction).
 */
final readonly class ProjectAccessRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(User $user, Project $project): ?ProjectAccess
    {
        return $this->em->getRepository(ProjectAccess::class)->findOneBy(['user' => $user, 'project' => $project]);
    }

    /**
     * Whether this username is a member of the project (by project id, so an unsaved project has no members).
     */
    public function isMember(string $username, Project $project): bool
    {
        $id = $project->id();
        if (null === $id) {
            return false;
        }

        return [] !== $this->em
            ->createQuery(\sprintf('SELECT a.id FROM %s a JOIN a.user u WHERE u.username = :username AND IDENTITY(a.project) = :project', ProjectAccess::class))
            ->setParameter('username', $username)
            ->setParameter('project', $id)
            ->setMaxResults(1)
            ->getScalarResult();
    }

    /**
     * @return list<string> project names, ordered
     */
    public function projectNamesOf(User $user): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT p.name AS name FROM %s a JOIN a.project p WHERE a.user = :user ORDER BY p.name ASC', ProjectAccess::class))
            ->setParameter('user', $user)
            ->getArrayResult();

        return array_column($rows, 'name');
    }

    /**
     * @return list<string> usernames, ordered
     */
    public function usernamesOf(Project $project): array
    {
        /** @var list<array{username: string}> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT u.username AS username FROM %s a JOIN a.user u WHERE a.project = :project ORDER BY u.username ASC', ProjectAccess::class))
            ->setParameter('project', $project)
            ->getArrayResult();

        return array_column($rows, 'username');
    }

    /**
     * Every project with the usernames that are members of it, from the stored rows: project name => usernames. A
     * project without members maps to an empty list.
     *
     * @return array<string, list<string>> ordered by project name, usernames ordered
     */
    public function membersByProject(): array
    {
        /** @var list<array{name: string}> $projects */
        $projects = $this->em
            ->createQuery(\sprintf('SELECT p.name AS name FROM %s p ORDER BY p.name ASC', Project::class))
            ->getArrayResult();
        $members = [];
        foreach ($projects as $row) {
            $members[$row['name']] = [];
        }

        /** @var list<array{name: string, username: string}> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT p.name AS name, u.username AS username FROM %s a JOIN a.project p JOIN a.user u ORDER BY p.name ASC, u.username ASC', ProjectAccess::class))
            ->getArrayResult();
        foreach ($rows as $row) {
            $members[$row['name']][] = $row['username'];
        }

        return $members;
    }

    public function add(ProjectAccess $access): void
    {
        $this->em->persist($access);
    }

    public function remove(ProjectAccess $access): void
    {
        $this->em->remove($access);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
