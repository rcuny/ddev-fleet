<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who did what, when: one row per audited action. The subject names what was touched ("project:demo/API_TOKEN") and
 * never carries a secret value.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_entry')]
#[ORM\Index(name: 'idx_audit_entry_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_entry_action', columns: ['action', 'created_at'])]
class AuditEntry
{
    public const int ACTOR_MAX = 64;
    public const int ACTION_MAX = 48;
    public const int SUBJECT_MAX = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::ACTOR_MAX)]
    private string $actor;

    #[ORM\Column(type: Types::STRING, length: self::ACTION_MAX)]
    private string $action;

    #[ORM\Column(type: Types::STRING, length: self::SUBJECT_MAX)]
    private string $subject;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $actor, string $action, string $subject, \DateTimeImmutable $createdAt)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->subject = $subject;
        $this->createdAt = $createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function actor(): string
    {
        return $this->actor;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
