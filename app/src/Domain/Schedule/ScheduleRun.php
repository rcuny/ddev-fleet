<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a schedule's history. A cron occurrence is claimed once: `claim` holds the occurrence for the ticker's
 * runs and is null for run now, and (schedule, claim) is unique, so two ticks racing for the same occurrence insert one
 * row. The job id is a plain column: the job log is pruned on its own schedule.
 */
#[ORM\Entity]
#[ORM\Table(name: 'schedule_run')]
#[ORM\Index(name: 'idx_schedule_run_schedule', columns: ['schedule_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_schedule_run_claim', columns: ['schedule_id', 'claim'])]
class ScheduleRun
{
    public const int ERROR_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Schedule::class)]
    #[ORM\JoinColumn(name: 'schedule_id', nullable: false, onDelete: 'CASCADE')]
    private Schedule $schedule;

    /** The cron occurrence this run answers (run now: the moment it was pressed). */
    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $occurrence;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $claim;

    #[ORM\Column(name: 'trigger_kind', type: Types::STRING, length: 16, enumType: ScheduleTrigger::class)]
    private ScheduleTrigger $trigger;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ScheduleOutcome::class)]
    private ScheduleOutcome $outcome = ScheduleOutcome::Claimed;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $jobId = null;

    #[ORM\Column(type: Types::STRING, length: self::ERROR_MAX, nullable: true)]
    private ?string $detail = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Schedule $schedule, \DateTimeImmutable $occurrence, ScheduleTrigger $trigger, \DateTimeImmutable $now)
    {
        $this->schedule = $schedule;
        $this->occurrence = $occurrence;
        $this->claim = ScheduleTrigger::Manual === $trigger ? null : $occurrence;
        $this->trigger = $trigger;
        $this->createdAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function schedule(): Schedule
    {
        return $this->schedule;
    }

    public function occurrence(): \DateTimeImmutable
    {
        return $this->occurrence;
    }

    public function trigger(): ScheduleTrigger
    {
        return $this->trigger;
    }

    public function outcome(): ScheduleOutcome
    {
        return $this->outcome;
    }

    public function jobId(): ?int
    {
        return $this->jobId;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function record(ScheduleOutcome $outcome, ?int $jobId, ?string $detail): void
    {
        $this->outcome = $outcome;
        $this->jobId = $jobId;
        $this->detail = null === $detail ? null : mb_substr($detail, 0, self::ERROR_MAX);
    }
}
