<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use Doctrine\ORM\EntityManagerInterface;

final readonly class ScheduleRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByName(string $name): ?Schedule
    {
        return $this->em->getRepository(Schedule::class)->findOneBy(['name' => $name]);
    }

    /**
     * @return list<Schedule> ordered by name
     */
    public function all(): array
    {
        return $this->em->getRepository(Schedule::class)->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return list<Schedule> ordered by name
     */
    public function enabled(): array
    {
        return $this->em->getRepository(Schedule::class)->findBy(['enabled' => true], ['name' => 'ASC']);
    }

    public function save(Schedule $schedule): void
    {
        $this->em->persist($schedule);
        $this->em->flush();
    }

    public function remove(Schedule $schedule): void
    {
        // The history goes with the schedule (the foreign key cascades); the runs already loaded would otherwise still
        // point at the removed row, so the entity manager starts over.
        $this->em->remove($schedule);
        $this->em->flush();
        $this->em->clear();
    }
}
