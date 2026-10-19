<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One unit of host work (deploy, start, destroy, ...). The instance id is a plain column, not a foreign key: the
 * history outlives the instance. The entity only stores; JobExecutor drives the status.
 */
#[ORM\Entity]
#[ORM\Table(name: 'job')]
#[ORM\Index(name: 'idx_job_status', columns: ['status'])]
#[ORM\Index(name: 'idx_job_instance', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_job_batch', columns: ['batch_id'])]
#[ORM\Index(name: 'idx_job_created', columns: ['created_at'])]
class Job
{
    public const int ERROR_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 63, nullable: true)]
    private ?string $instanceId;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $projectName;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: JobStatus::class)]
    private JobStatus $status = JobStatus::Queued;

    #[ORM\Column(name: 'trigger_kind', type: Types::STRING, length: 16, enumType: JobTrigger::class)]
    private JobTrigger $trigger;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $requestedBy;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $params;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $batchId;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $sequential;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $retryOf;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $exitCode = null;

    #[ORM\Column(type: Types::STRING, length: self::ERROR_MAX, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        string $type,
        ?string $instanceId,
        ?string $projectName,
        JobTrigger $trigger,
        string $requestedBy,
        array $params = [],
        ?string $batchId = null,
        bool $sequential = false,
        ?int $retryOf = null,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->type = $type;
        $this->instanceId = $instanceId;
        $this->projectName = $projectName;
        $this->trigger = $trigger;
        $this->requestedBy = $requestedBy;
        $this->params = $params;
        $this->batchId = $batchId;
        $this->sequential = $sequential;
        $this->retryOf = $retryOf;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function instanceId(): ?string
    {
        return $this->instanceId;
    }

    public function projectName(): ?string
    {
        return $this->projectName;
    }

    public function status(): JobStatus
    {
        return $this->status;
    }

    public function trigger(): JobTrigger
    {
        return $this->trigger;
    }

    public function requestedBy(): string
    {
        return $this->requestedBy;
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    public function batchId(): ?string
    {
        return $this->batchId;
    }

    public function isSequential(): bool
    {
        return $this->sequential;
    }

    public function retryOf(): ?int
    {
        return $this->retryOf;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markRunning(\DateTimeImmutable $at): void
    {
        $this->status = JobStatus::Running;
        $this->startedAt = $at;
    }

    public function finish(JobStatus $status, ?int $exitCode, ?string $error, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->exitCode = $exitCode;
        $this->error = null === $error ? null : mb_substr($error, 0, self::ERROR_MAX);
        $this->finishedAt = $at;
    }
}
