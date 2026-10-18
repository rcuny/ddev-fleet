<?php

declare(strict_types=1);

namespace App\Domain\Host;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The host's own settings: a singleton row with id 1 (see HostSettingsRepository).
 *
 * The setup token itself is never stored, only its sha256 hex digest.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_settings')]
class HostSettings
{
    public const int ID = 1;

    // SMALLINT, not INTEGER: a single-column INTEGER primary key is a rowid alias in SQLite, which DBAL
    // introspects as auto-increment and then reports as drift against this fixed (never generated) id.
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = self::ID;

    #[ORM\Column(type: Types::STRING, length: 253)]
    private string $domain;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $acmeEmail = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: TlsMode::class)]
    private TlsMode $tlsMode = TlsMode::Http01;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $setupTokenHash = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $setupTokenExpiresAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $setupCompletedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $domain, ?\DateTimeImmutable $now = null)
    {
        $this->domain = $domain;
        $this->createdAt = $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): int
    {
        return $this->id;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function changeDomain(string $domain): void
    {
        $this->domain = $domain;
        $this->touch();
    }

    public function acmeEmail(): ?string
    {
        return $this->acmeEmail;
    }

    public function changeAcmeEmail(?string $acmeEmail): void
    {
        $this->acmeEmail = $acmeEmail;
        $this->touch();
    }

    public function tlsMode(): TlsMode
    {
        return $this->tlsMode;
    }

    public function changeTlsMode(TlsMode $tlsMode): void
    {
        $this->tlsMode = $tlsMode;
        $this->touch();
    }

    public function setupTokenHash(): ?string
    {
        return $this->setupTokenHash;
    }

    public function setupTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->setupTokenExpiresAt;
    }

    /**
     * Stores a new token digest, replacing (invalidating) any previous one.
     *
     * @param string $tokenHash sha256 hex digest of the token
     */
    public function storeSetupToken(string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $tokenHash)) {
            throw new \InvalidArgumentException('The setup token hash must be a sha256 hex digest.');
        }
        $this->setupTokenHash = $tokenHash;
        $this->setupTokenExpiresAt = $expiresAt;
        $this->touch();
    }

    public function clearSetupToken(): void
    {
        $this->setupTokenHash = null;
        $this->setupTokenExpiresAt = null;
        $this->touch();
    }

    public function setupCompletedAt(): ?\DateTimeImmutable
    {
        return $this->setupCompletedAt;
    }

    public function markSetupComplete(?\DateTimeImmutable $at = null): void
    {
        $this->setupCompletedAt = $at ?? new \DateTimeImmutable();
        $this->touch($this->setupCompletedAt);
    }

    public function isSetupComplete(): bool
    {
        return null !== $this->setupCompletedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
