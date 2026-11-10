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

    /**
     * Remembers the request; an existing one for the instance is replaced (latest wins). One atomic upsert, so two
     * concurrent webhooks neither collide on the primary key nor lose a count. Returns how many requests are folded.
     */
    public function remember(string $instanceId, ?string $branch, ?string $template, string $requestedBy, \DateTimeImmutable $at): int
    {
        $connection = $this->em->getConnection();
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        $connection->executeStatement(
            'INSERT INTO pending_redeploy (instance_id, branch, template, requested_by, requested_at, requests) VALUES (?, ?, ?, ?, ?, 1) '
            .'ON CONFLICT(instance_id) DO UPDATE SET branch = excluded.branch, template = excluded.template, '
            .'requested_by = excluded.requested_by, requested_at = excluded.requested_at, requests = requests + 1',
            [$instanceId, $branch, $template, $requestedBy, $at->format('Y-m-d H:i:s')],
        );
        $known = $this->em->getUnitOfWork()->tryGetById($instanceId, PendingRedeploy::class);
        if ($known instanceof PendingRedeploy) {
            $this->em->refresh($known);
        }

        return (int) $connection->fetchOne('SELECT requests FROM pending_redeploy WHERE instance_id = ?', [$instanceId]);
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
