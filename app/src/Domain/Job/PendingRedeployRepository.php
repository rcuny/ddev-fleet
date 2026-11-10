<?php

declare(strict_types=1);

namespace App\Domain\Job;

use Doctrine\ORM\EntityManagerInterface;

final readonly class PendingRedeployRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $instanceId): ?PendingRedeploy
    {
        return $this->em->getRepository(PendingRedeploy::class)->find($instanceId);
    }

    /** Remembers the request; an existing one for the instance is replaced (latest wins). */
    public function remember(string $instanceId, ?string $branch, ?string $template, string $requestedBy, \DateTimeImmutable $at): PendingRedeploy
    {
        $pending = $this->find($instanceId);
        if ($pending instanceof PendingRedeploy) {
            $pending->replaceWith($branch, $template, $requestedBy, $at);
        } else {
            $pending = new PendingRedeploy($instanceId, $branch, $template, $requestedBy, $at);
            $this->em->persist($pending);
        }
        $this->em->flush();

        return $pending;
    }

    public function remove(PendingRedeploy $pending): void
    {
        $this->em->remove($pending);
        $this->em->flush();
    }

    /** @return list<PendingRedeploy> oldest first */
    public function all(): array
    {
        /** @var list<PendingRedeploy> $all */
        $all = $this->em->getRepository(PendingRedeploy::class)->findBy([], ['requestedAt' => 'ASC']);

        return $all;
    }
}
