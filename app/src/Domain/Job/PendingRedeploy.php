<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * FLE-220: a redeploy a webhook asked for while its instance had a queued or running job. One row per instance, the
 * latest request wins (branch, template and requester are overwritten). When the active job ends, the follow-up job is
 * queued and the row removed. The `job` table's one-active-job-per-instance constraint is untouched: this row is not
 * a job.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pending_redeploy')]
class PendingRedeploy
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 63)]
    private string $instanceId;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $branch;

    #[ORM\Column(type: Types::STRING, length: 63, nullable: true)]
    private ?string $template;

    #[ORM\Column(type: Types::STRING, length: 254)]
    private string $requestedBy;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $requests = 1;

    public function __construct(string $instanceId, ?string $branch, ?string $template, string $requestedBy, \DateTimeImmutable $at)
    {
        $this->instanceId = $instanceId;
        $this->branch = $branch;
        $this->template = $template;
        $this->requestedBy = $requestedBy;
        $this->requestedAt = $at;
    }

    /** The newest request replaces the older one. */
    public function replaceWith(?string $branch, ?string $template, string $requestedBy, \DateTimeImmutable $at): void
    {
        $this->branch = $branch;
        $this->template = $template;
        $this->requestedBy = $requestedBy;
        $this->requestedAt = $at;
        ++$this->requests;
    }

    public function instanceId(): string
    {
        return $this->instanceId;
    }

    public function branch(): ?string
    {
        return $this->branch;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    public function requestedBy(): string
    {
        return $this->requestedBy;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    /** How many pushes were folded into this one redeploy. */
    public function requests(): int
    {
        return $this->requests;
    }
}
