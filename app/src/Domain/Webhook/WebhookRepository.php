<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WebhookRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function endpoint(string $projectName, WebhookSource $source): ?WebhookEndpoint
    {
        return $this->em
            ->createQuery(\sprintf('SELECT e FROM %s e JOIN e.project p WHERE p.name = :project AND e.source = :source', WebhookEndpoint::class))
            ->setParameter('project', $projectName)
            ->setParameter('source', $source)
            ->getOneOrNullResult();
    }

    /**
     * @return list<WebhookEndpoint> ordered by project name, then source
     */
    public function endpoints(): array
    {
        /** @var list<WebhookEndpoint> $all */
        $all = $this->em
            ->createQuery(\sprintf('SELECT e, p FROM %s e JOIN e.project p ORDER BY p.name ASC, e.source ASC', WebhookEndpoint::class))
            ->getResult();

        return $all;
    }

    public function findRule(int $id): ?WebhookRule
    {
        return $this->em->find(WebhookRule::class, $id);
    }

    public function nextRulePosition(WebhookEndpoint $endpoint): int
    {
        $max = $this->em
            ->createQuery(\sprintf('SELECT MAX(r.position) FROM %s r WHERE r.endpoint = :endpoint', WebhookRule::class))
            ->setParameter('endpoint', $endpoint)
            ->getSingleScalarResult();

        return null === $max ? 1 : (int) $max + 1;
    }

    public function saveEndpoint(WebhookEndpoint $endpoint): void
    {
        $this->em->persist($endpoint);
        $this->em->flush();
    }

    public function saveRule(WebhookRule $rule): void
    {
        $this->em->persist($rule);
        $this->em->flush();
        // The loaded collection of the endpoint does not know about the new row yet.
        $this->em->refresh($rule->endpoint());
    }

    public function removeRule(WebhookRule $rule): void
    {
        $endpoint = $rule->endpoint();
        $this->em->remove($rule);
        $this->em->flush();
        $this->em->refresh($endpoint);
    }

    public function saveDelivery(WebhookDelivery $delivery): void
    {
        $this->em->persist($delivery);
        $this->em->flush();
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * Stores what an action did on a delivery. The action may have reset the entity manager (a label race in the
     * instance service), which leaves the delivery detached: it is then loaded again by id.
     */
    public function recordOutcome(WebhookDelivery $delivery, string $note, ?int $jobId, bool $failed): void
    {
        if (!$this->em->contains($delivery) && null !== $delivery->id()) {
            $delivery = $this->em->find(WebhookDelivery::class, $delivery->id()) ?? $delivery;
        }
        $delivery->recordAction($note, $jobId);
        if ($failed) {
            $delivery->recordFailure($note);
        }
        $this->em->flush();
    }

    /**
     * Whether a delivery with this id already consumed it (a retry of the same event).
     */
    public function dedupeSeen(string $projectName, WebhookSource $source, string $dedupeId): bool
    {
        $found = $this->em
            ->createQuery(\sprintf('SELECT d.id FROM %s d WHERE d.projectName = :project AND d.source = :source AND d.dedupeId = :id', WebhookDelivery::class))
            ->setParameter('project', $projectName)
            ->setParameter('source', $source)
            ->setParameter('id', $dedupeId)
            ->setMaxResults(1)
            ->getArrayResult();

        return [] !== $found;
    }

    /**
     * Whether a delivery that matched a rule carried exactly this body since the cut-off (a replay of a signed body).
     */
    public function bodySeen(string $projectName, WebhookSource $source, string $bodyHash, \DateTimeImmutable $since): bool
    {
        $found = $this->em
            ->createQuery(\sprintf('SELECT d.id FROM %s d WHERE d.projectName = :project AND d.source = :source AND d.bodyHash = :hash AND d.receivedAt >= :since', WebhookDelivery::class))
            ->setParameter('project', $projectName)
            ->setParameter('source', $source)
            ->setParameter('hash', $bodyHash)
            ->setParameter('since', $since, UtcDateTimeImmutableType::NAME)
            ->setMaxResults(1)
            ->getArrayResult();

        return [] !== $found;
    }

    /**
     * Newest first. `$source` and `$result` filter on that column when given.
     *
     * @return list<WebhookDelivery>
     */
    public function deliveries(?string $projectName, bool $hideIgnored, int $limit, int $offset = 0, ?WebhookSource $source = null, ?string $result = null): array
    {
        $dql = \sprintf('SELECT d FROM %s d WHERE 1 = 1', WebhookDelivery::class);
        if (null !== $projectName) {
            $dql .= ' AND d.projectName = :project';
        }
        if ($hideIgnored) {
            $dql .= ' AND d.result <> :ignored';
        }
        if (null !== $source) {
            $dql .= ' AND d.source = :source';
        }
        if (null !== $result) {
            $dql .= ' AND d.result = :result';
        }
        $query = $this->em->createQuery($dql.' ORDER BY d.receivedAt DESC, d.id DESC')->setMaxResults($limit)->setFirstResult($offset);
        if (null !== $projectName) {
            $query->setParameter('project', $projectName);
        }
        if ($hideIgnored) {
            $query->setParameter('ignored', WebhookDelivery::IGNORED);
        }
        if (null !== $source) {
            $query->setParameter('source', $source);
        }
        if (null !== $result) {
            $query->setParameter('result', $result);
        }

        /** @var list<WebhookDelivery> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    /**
     * The newest delivery of every project and source, in one query: "project/source" => delivery.
     *
     * @return array<string, WebhookDelivery>
     */
    public function latestDeliveries(): array
    {
        /** @var list<WebhookDelivery> $rows */
        $rows = $this->em
            ->createQuery(\sprintf('SELECT d FROM %1$s d WHERE d.id = (SELECT MAX(x.id) FROM %1$s x WHERE x.projectName = d.projectName AND x.source = d.source)', WebhookDelivery::class))
            ->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->projectName().'/'.$row->source()->value] = $row;
        }

        return $latest;
    }

    /**
     * Frees the dedupe ids older than the cut-off and deletes the oldest log rows.
     */
    public function prune(\DateTimeImmutable $dedupeBefore, \DateTimeImmutable $deleteBefore): void
    {
        $this->em
            ->createQuery(\sprintf('UPDATE %s d SET d.dedupeId = NULL WHERE d.dedupeId IS NOT NULL AND d.receivedAt < :before', WebhookDelivery::class))
            ->setParameter('before', $dedupeBefore, UtcDateTimeImmutableType::NAME)
            ->execute();
        $this->em
            ->createQuery(\sprintf('DELETE FROM %s d WHERE d.receivedAt < :before', WebhookDelivery::class))
            ->setParameter('before', $deleteBefore, UtcDateTimeImmutableType::NAME)
            ->execute();
    }
}
