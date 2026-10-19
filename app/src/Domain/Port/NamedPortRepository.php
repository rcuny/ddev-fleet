<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Project\Project;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NamedPortRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByName(string $name): ?NamedPort
    {
        return $this->em->getRepository(NamedPort::class)->findOneBy(['name' => $name]);
    }

    /**
     * The catalogue entries with these names, ordered by name; unknown names are left out.
     *
     * @param list<string> $names
     *
     * @return list<NamedPort>
     */
    public function findManyByNames(array $names): array
    {
        if ([] === $names) {
            return [];
        }

        return $this->em->getRepository(NamedPort::class)->findBy(['name' => $names], ['name' => 'ASC']);
    }

    /**
     * @return list<NamedPort> ordered by name
     */
    public function all(): array
    {
        return $this->em->getRepository(NamedPort::class)->findBy([], ['name' => 'ASC']);
    }

    /**
     * The names of the projects that have this port assigned.
     *
     * @return list<string> ordered by name
     */
    public function projectNamesUsing(NamedPort $port): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT p.name FROM %s p JOIN p.ports n WHERE n = :port ORDER BY p.name ASC', Project::class))
            ->setParameter('port', $port)
            ->getArrayResult();

        return array_column($rows, 'name');
    }

    public function save(NamedPort $port): void
    {
        $this->em->persist($port);
        $this->em->flush();
    }

    public function remove(NamedPort $port): void
    {
        $this->em->remove($port);
        $this->em->flush();
    }
}
