<?php

declare(strict_types=1);

namespace App\Domain\Project;

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
