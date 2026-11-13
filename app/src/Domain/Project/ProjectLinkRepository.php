<?php

declare(strict_types=1);

namespace App\Domain\Project;

use Doctrine\ORM\EntityManagerInterface;

final readonly class ProjectLinkRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return list<ProjectLink> ordered by source, target, service, port
     */
    public function all(): array
    {
        return $this->em->createQuery(\sprintf('SELECT l, s, t FROM %s l JOIN l.source s JOIN l.target t ORDER BY s.name ASC, t.name ASC, l.service ASC, l.port ASC, l.targetLabel ASC', ProjectLink::class))->getResult();
    }

    /**
     * The links a project declares (outgoing) or receives (incoming).
     *
     * @return list<ProjectLink>
     */
    public function outgoing(Project $project): array
    {
        return array_values(array_filter($this->all(), static fn (ProjectLink $l): bool => $l->source()->name() === $project->name()));
    }

    /**
     * @return list<ProjectLink>
     */
    public function incoming(Project $project): array
    {
        return array_values(array_filter($this->all(), static fn (ProjectLink $l): bool => $l->target()->name() === $project->name()));
    }

    public function find(Project $source, Project $target, string $service, int $port, string $targetLabel): ?ProjectLink
    {
        return $this->em->getRepository(ProjectLink::class)->findOneBy([
            'source' => $source, 'target' => $target, 'service' => $service, 'port' => $port, 'targetLabel' => $targetLabel,
        ]);
    }

    public function save(ProjectLink $link): void
    {
        $this->em->persist($link);
        $this->em->flush();
    }

    public function delete(ProjectLink $link): void
    {
        $this->em->remove($link);
        $this->em->flush();
    }
}
