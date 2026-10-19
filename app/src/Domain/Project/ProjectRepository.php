<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Domain\Template\Template;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProjectRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByName(string $name): ?Project
    {
        return $this->em->getRepository(Project::class)->findOneBy(['name' => $name]);
    }

    /**
     * @return list<Project> ordered by name
     */
    public function all(): array
    {
        return $this->em->getRepository(Project::class)->findBy([], ['name' => 'ASC']);
    }

    /**
     * The names of the project's templates, read from the database (the loaded collection goes stale when a template
     * is saved through TemplateRepository).
     *
     * @return list<string> ordered by name
     */
    public function templateNames(Project $project): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT t.name FROM %s t WHERE t.project = :project ORDER BY t.name ASC', Template::class))
            ->setParameter('project', $project)
            ->getArrayResult();

        return array_column($rows, 'name');
    }

    /**
     * How many templates every project has, in one query (the project list page): project name => count. A project
     * without templates is absent.
     *
     * @return array<string, int>
     */
    public function templateCounts(): array
    {
        /** @var list<array{name: string, total: int|string}> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT p.name AS name, COUNT(t.id) AS total FROM %s t JOIN t.project p GROUP BY p.name', Template::class))
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['name']] = (int) $row['total'];
        }

        return $counts;
    }

    public function save(Project $project): void
    {
        $this->em->persist($project);
        $this->em->flush();
    }

    public function remove(Project $project): void
    {
        $this->em->remove($project);
        $this->em->flush();
    }
}
