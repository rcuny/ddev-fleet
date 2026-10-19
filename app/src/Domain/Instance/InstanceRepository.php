<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Domain\Project\Project;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityIdentityCollisionException;

final readonly class InstanceRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?Instance
    {
        return $this->em->getRepository(Instance::class)->find($id);
    }

    /**
     * @return list<Instance> ordered by id
     */
    public function all(): array
    {
        return $this->em->getRepository(Instance::class)->findBy([], ['id' => 'ASC']);
    }

    /**
     * @return list<Instance> ordered by id
     */
    public function byProject(Project $project): array
    {
        return $this->em->getRepository(Instance::class)->findBy(['project' => $project], ['id' => 'ASC']);
    }

    /**
     * @return list<string> ordered
     */
    public function ids(): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM instance ORDER BY id ASC');

        return $ids;
    }

    /**
     * @return array<string, int> state value => number of instances; a state nobody is in is absent
     */
    public function countByState(): array
    {
        /** @var list<array{state: string, total: int|string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT state, COUNT(*) AS total FROM instance GROUP BY state');

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['state']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Inserts all the instances in one flush (one transaction): either every row exists afterwards or none does.
     * A duplicate id (in the database or already held by this manager) throws LabelTaken. The entity manager is closed by the failed flush; the caller resets it
     * (ManagerRegistry::resetManager()) before retrying with the next label.
     *
     * @param list<Instance> $instances
     *
     * @throws LabelTaken
     */
    public function createMany(array $instances): void
    {
        try {
            foreach ($instances as $instance) {
                $this->em->persist($instance);
            }
            $this->em->flush();
        } catch (EntityIdentityCollisionException $e) {
            // This manager already holds an instance with that id: nothing of the batch must be written.
            $held = $this->firstHeld($instances);
            foreach ($instances as $instance) {
                if ($this->em->contains($instance)) {
                    $this->em->detach($instance);
                }
            }

            throw new LabelTaken($held, $e);
        } catch (UniqueConstraintViolationException $e) {
            $ids = array_map(static fn (Instance $i): string => $i->id(), $instances);
            $taken = $this->takenAmong($ids);

            throw new LabelTaken($taken[0] ?? $ids[0] ?? '', $e);
        }
    }

    public function save(Instance $instance): void
    {
        $this->em->persist($instance);
        $this->em->flush();
    }

    public function remove(Instance $instance): void
    {
        $this->em->remove($instance);
        $this->em->flush();
    }

    /**
     * @param list<Instance> $instances
     */
    private function firstHeld(array $instances): string
    {
        foreach ($instances as $instance) {
            $held = $this->em->getUnitOfWork()->tryGetById($instance->id(), Instance::class);
            if ($held instanceof Instance && $held !== $instance) {
                return $instance->id();
            }
        }

        return '';
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function takenAmong(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<string> $taken */
        $taken = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM instance WHERE id IN (?) ORDER BY id ASC',
            [$ids],
            [ArrayParameterType::STRING],
        );

        return $taken;
    }
}
