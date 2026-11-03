<?php

declare(strict_types=1);

namespace App\Domain\Project;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Domain\User\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user is a member of a project: may operate its instances (ProjectVoter::OPERATE) and open their sites through
 * Authelia. One row per pair. Both foreign keys cascade, so deleting the user or the project removes the row in the
 * same statement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_access')]
#[ORM\UniqueConstraint(name: 'uniq_project_access_pair', columns: ['user_id', 'project_id'])]
class ProjectAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Project $project, ?\DateTimeImmutable $now = null)
    {
        $this->user = $user;
        $this->project = $project;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
