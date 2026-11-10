<?php

declare(strict_types=1);

namespace App\Domain\ApiToken;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A service token of the HTTP API (FLE-265 design, section 4). Only the SHA-256 of the full token is stored; the
 * token itself is shown once, when it is issued. Expiry is required.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_token')]
#[ORM\UniqueConstraint(name: 'uniq_api_token_hash', columns: ['token_hash'])]
class ApiToken
{
    public const int NAME_MAX = 32;
    public const int DESCRIPTION_MAX = 255;
    public const int NAME_ACTOR_MAX = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: self::DESCRIPTION_MAX)]
    private string $description;

    /** Lower-case hex SHA-256 of the full token. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $tokenHash;

    /** The first characters of the secret part, so a person can tell tokens apart in a list. Not a secret. */
    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $prefixHint;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes;

    /** @var list<string> project names; `*` (every project) is honoured for a read-only token only */
    #[ORM\Column(type: Types::JSON)]
    private array $projects;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $instanceGlobs;

    /** @var list<string> CIDR ranges or addresses; empty means no IP restriction */
    #[ORM\Column(type: Types::JSON)]
    private array $ipAllow;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $createdBy;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $rotatedFrom = null;

    /**
     * @param list<string> $scopes
     * @param list<string> $projects
     * @param list<string> $instanceGlobs
     * @param list<string> $ipAllow
     */
    public function __construct(
        string $name,
        string $description,
        string $tokenHash,
        string $prefixHint,
        array $scopes,
        array $projects,
        array $instanceGlobs,
        array $ipAllow,
        string $createdBy,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        ?int $rotatedFrom = null,
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->tokenHash = $tokenHash;
        $this->prefixHint = $prefixHint;
        $this->scopes = $scopes;
        $this->projects = $projects;
        $this->instanceGlobs = $instanceGlobs;
        $this->ipAllow = $ipAllow;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->rotatedFrom = $rotatedFrom;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function prefixHint(): string
    {
        return $this->prefixHint;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * @return list<string>
     */
    public function projects(): array
    {
        return $this->projects;
    }

    /**
     * @return list<string>
     */
    public function instanceGlobs(): array
    {
        return $this->instanceGlobs;
    }

    /**
     * @return list<string>
     */
    public function ipAllow(): array
    {
        return $this->ipAllow;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function lastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function rotatedFrom(): ?int
    {
        return $this->rotatedFrom;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    /** Shortens the life of a token (a rotation grace period); never extends it. */
    public function expireNoLaterThan(\DateTimeImmutable $at): void
    {
        if ($at < $this->expiresAt) {
            $this->expiresAt = $at;
        }
    }

    public function recordUse(\DateTimeImmutable $now, string $ip): void
    {
        $this->lastUsedAt = $now;
        $this->lastUsedIp = mb_strcut($ip, 0, 45);
    }
}
