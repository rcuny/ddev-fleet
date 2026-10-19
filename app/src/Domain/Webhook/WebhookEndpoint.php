<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Domain\Project\Project;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One webhook receiver: a project and a source (Jira or Bitbucket). It owns the HMAC secret, sealed with SecretBox
 * (only ciphertext is stored; WebhookSecrets seals and opens it) and its rules. It disappears with its project, and so
 * does its secret. The entity only stores.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_endpoint')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_endpoint_project_source', columns: ['project_id', 'source'])]
class WebhookEndpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: WebhookSource::class)]
    private WebhookSource $source;

    #[ORM\Column(type: Types::BINARY)]
    private string $ciphertext;

    #[ORM\Column(type: Types::BINARY)]
    private string $nonce;

    #[ORM\Column(type: Types::INTEGER)]
    private int $keyVersion;

    /** @var Collection<int, WebhookRule> */
    #[ORM\OneToMany(targetEntity: WebhookRule::class, mappedBy: 'endpoint', cascade: ['remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $rules;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $rotatedAt;

    public function __construct(Project $project, WebhookSource $source, string $ciphertext, string $nonce, int $keyVersion, \DateTimeImmutable $now)
    {
        $this->project = $project;
        $this->source = $source;
        $this->ciphertext = $ciphertext;
        $this->nonce = $nonce;
        $this->keyVersion = $keyVersion;
        $this->rules = new ArrayCollection();
        $this->createdAt = $now;
        $this->rotatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function source(): WebhookSource
    {
        return $this->source;
    }

    public function ciphertext(): string
    {
        return $this->ciphertext;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function keyVersion(): int
    {
        return $this->keyVersion;
    }

    /**
     * @return list<WebhookRule>
     */
    public function rules(): array
    {
        return array_values($this->rules->toArray());
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rotatedAt(): \DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function replaceSecret(string $ciphertext, string $nonce, int $keyVersion, \DateTimeImmutable $now): void
    {
        $this->ciphertext = $ciphertext;
        $this->nonce = $nonce;
        $this->keyVersion = $keyVersion;
        $this->rotatedAt = $now;
    }
}
