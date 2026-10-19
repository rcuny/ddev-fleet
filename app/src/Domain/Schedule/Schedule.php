<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recurring job: a 5-field cron expression read in an IANA timezone, and the action to run. The entity only stores;
 * ScheduleTicker decides what is due. `lastOccurrence` is the cursor: the newest occurrence already dealt with (fired,
 * skipped or missed), so a restarted ticker never repeats one and a resumed schedule never catches up.
 */
#[ORM\Entity]
#[ORM\Table(name: 'schedule')]
#[ORM\UniqueConstraint(name: 'uniq_schedule_name', columns: ['name'])]
class Schedule
{
    public const int NAME_MAX = 40;
    public const int CRON_MAX = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: self::CRON_MAX)]
    private string $cron;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $timezone;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $action;

    /** A plain column, not a foreign key: a destroyed instance must not delete the schedule or its history. */
    #[ORM\Column(type: Types::STRING, length: 63, nullable: true)]
    private ?string $instanceId;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $lastOccurrence = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $cron, string $timezone, string $action, ?string $instanceId, \DateTimeImmutable $now)
    {
        $this->name = $name;
        $this->cron = $cron;
        $this->timezone = $timezone;
        $this->action = $action;
        $this->instanceId = $instanceId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function cron(): string
    {
        return $this->cron;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function instanceId(): ?string
    {
        return $this->instanceId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function lastOccurrence(): ?\DateTimeImmutable
    {
        return $this->lastOccurrence;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The point after which an occurrence is new: the cursor, or the creation time of a schedule that never fired.
     */
    public function cursor(): \DateTimeImmutable
    {
        return $this->lastOccurrence ?? $this->createdAt;
    }

    public function change(string $cron, string $timezone, string $action, ?string $instanceId, \DateTimeImmutable $now): void
    {
        $this->cron = $cron;
        $this->timezone = $timezone;
        $this->action = $action;
        $this->instanceId = $instanceId;
        $this->updatedAt = $now;
    }

    public function setEnabled(bool $enabled, \DateTimeImmutable $now): void
    {
        $this->enabled = $enabled;
        $this->updatedAt = $now;
    }

    public function advanceTo(\DateTimeImmutable $occurrence): void
    {
        if (null === $this->lastOccurrence || $occurrence > $this->lastOccurrence) {
            $this->lastOccurrence = $occurrence;
        }
    }
}
