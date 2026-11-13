<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * FLE-299: a declared link. The web container of every instance of the source project may open TCP connections to one
 * service port of the target project's instance (same label, or one fixed label). One way only: the target cannot connect back.
 * Both foreign keys cascade, so deleting either project removes its links; the next isolation pass removes the networks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_link')]
#[ORM\UniqueConstraint(name: 'uniq_project_link', columns: ['source_id', 'target_id', 'service', 'port', 'target_label'])]
class ProjectLink
{
    /** The target label that means "the instance of the target project with the source instance's own label". */
    public const string SAME_LABEL = 'same';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'source_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Project $source;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'target_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Project $target;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $service;

    #[ORM\Column(type: Types::INTEGER)]
    private int $port;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $targetLabel;

    #[ORM\Column(type: Types::TEXT)]
    private string $note;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $createdBy;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $source, Project $target, string $service, int $port, string $targetLabel, string $note, string $createdBy, ?\DateTimeImmutable $now = null)
    {
        $this->source = $source;
        $this->target = $target;
        $this->service = $service;
        $this->port = $port;
        $this->targetLabel = $targetLabel;
        $this->note = $note;
        $this->createdBy = $createdBy;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function source(): Project
    {
        return $this->source;
    }

    public function target(): Project
    {
        return $this->target;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function targetLabel(): string
    {
        return $this->targetLabel;
    }

    public function isSameLabel(): bool
    {
        return self::SAME_LABEL === $this->targetLabel;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
