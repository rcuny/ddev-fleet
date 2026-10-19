<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The moment the ticker last finished a tick: a singleton row with id 1 (see SchedulerHeartbeatRepository). A tick
 * that finds the previous one more than ScheduleTicker::ON_TIME_SECONDS back knows the ticker was down, which is what
 * makes a late run a catch-up.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scheduler_heartbeat')]
class SchedulerHeartbeat
{
    public const int ID = 1;

    // SMALLINT, not INTEGER: see HostSettings (a single-column INTEGER primary key is a rowid alias in SQLite).
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = self::ID;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $lastTickAt;

    public function __construct(\DateTimeImmutable $lastTickAt)
    {
        $this->lastTickAt = $lastTickAt;
    }

    public function lastTickAt(): \DateTimeImmutable
    {
        return $this->lastTickAt;
    }
}
