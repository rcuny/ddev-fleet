<?php

declare(strict_types=1);

namespace App\Domain\Ssh;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The public half and fingerprint of one of the host's SSH key pairs. The private key stays on disk
 * (App\Fleet\FleetPaths::sshPrivateKey()), never in the database.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ssh_key')]
#[ORM\UniqueConstraint(name: 'uniq_ssh_key_role', columns: ['role'])]
class SshKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: SshKeyRole::class)]
    private SshKeyRole $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $fingerprint;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(SshKeyRole $role, string $publicKey, string $fingerprint, ?\DateTimeImmutable $now = null)
    {
        $this->role = $role;
        $this->publicKey = $publicKey;
        $this->fingerprint = $fingerprint;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function role(): SshKeyRole
    {
        return $this->role;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * Swaps in a regenerated or imported key pair; createdAt records when this key was generated.
     */
    public function replaceKey(string $publicKey, string $fingerprint, ?\DateTimeImmutable $now = null): void
    {
        $this->publicKey = $publicKey;
        $this->fingerprint = $fingerprint;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
