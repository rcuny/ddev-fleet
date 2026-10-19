<?php

declare(strict_types=1);

namespace App\Domain\Instance;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Domain\Project\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One deployed (or being deployed) copy of a project. The id `{project}--{label}` is the primary key: the database is
 * the source of truth, nothing is written into the checkout. The entity only stores; the services validate.
 */
#[ORM\Entity]
#[ORM\Table(name: 'instance')]
#[ORM\Index(name: 'idx_instance_project', columns: ['project_id'])]
#[ORM\Index(name: 'idx_instance_state', columns: ['state'])]
class Instance
{
    public const int ID_MAX = 63;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: self::ID_MAX)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Project $project;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $label;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $templateName;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $branch;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: InstanceState::class)]
    private InstanceState $state = InstanceState::Deploying;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $headSha = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ramMib = null;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $requestedBy;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $lastObservedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $deployedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Project $project, string $label, string $templateName, string $branch, string $requestedBy, ?\DateTimeImmutable $now = null)
    {
        $now ??= new \DateTimeImmutable();
        $this->id = $project->name().'--'.$label;
        $this->project = $project;
        $this->label = $label;
        $this->templateName = $templateName;
        $this->branch = $branch;
        $this->requestedBy = $requestedBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function templateName(): string
    {
        return $this->templateName;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function state(): InstanceState
    {
        return $this->state;
    }

    public function headSha(): ?string
    {
        return $this->headSha;
    }

    public function ramMib(): ?int
    {
        return $this->ramMib;
    }

    public function requestedBy(): string
    {
        return $this->requestedBy;
    }

    public function lastObservedAt(): ?\DateTimeImmutable
    {
        return $this->lastObservedAt;
    }

    public function deployedAt(): ?\DateTimeImmutable
    {
        return $this->deployedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeState(InstanceState $state, ?\DateTimeImmutable $now = null): void
    {
        $this->state = $state;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function markDeployed(\DateTimeImmutable $at): void
    {
        $this->deployedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * What the watcher saw on the host: the observed state and, when known, the memory the containers use.
     */
    public function observe(InstanceState $state, ?int $ramMib, \DateTimeImmutable $at): void
    {
        $this->state = $state;
        $this->ramMib = $ramMib;
        $this->lastObservedAt = $at;
        $this->updatedAt = $at;
    }

    public function recordHead(?string $sha): void
    {
        $this->headSha = $sha;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
