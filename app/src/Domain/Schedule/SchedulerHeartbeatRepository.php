<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SchedulerHeartbeatRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return \DateTimeImmutable|null when the previous tick finished, null when the ticker never ran
     */
    public function last(): ?\DateTimeImmutable
    {
        $value = $this->em->getConnection()->fetchOne('SELECT last_tick_at FROM scheduler_heartbeat WHERE id = ?', [SchedulerHeartbeat::ID]);
        if (false === $value || !\is_string($value)) {
            return null;
        }

        return Type::getType(UtcDateTimeImmutableType::NAME)->convertToPHPValue($value, $this->em->getConnection()->getDatabasePlatform());
    }

    public function beat(\DateTimeImmutable $now): void
    {
        $type = Type::getType(UtcDateTimeImmutableType::NAME);
        // A plain upsert keeps the entity manager untouched (it may be reset or closed by a failed schedule).
        $this->em->getConnection()->executeStatement(
            'INSERT INTO scheduler_heartbeat (id, last_tick_at) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET last_tick_at = excluded.last_tick_at',
            [SchedulerHeartbeat::ID, $type->convertToDatabaseValue($now, $this->em->getConnection()->getDatabasePlatform())],
        );
    }
}
