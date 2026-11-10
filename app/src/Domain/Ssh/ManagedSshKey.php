<?php

declare(strict_types=1);

namespace App\Domain\Ssh;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The public half and fingerprint of an extra SSH key Fleet created or imported (FLE-266). The private key is the file
 * `ssh/<name>_ed25519` (App\Fleet\FleetPaths::sshExtraKey()), never the database. A key file with no row here was
 * placed by hand and is shown as "external".
 */
#[ORM\Entity]
#[ORM\Table(name: 'ssh_extra_key')]
#[ORM\UniqueConstraint(name: 'uniq_ssh_extra_key_name', columns: ['name'])]
class ManagedSshKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ManagedSshKeySource::class)]
    private ManagedSshKeySource $source;

    #[ORM\Column(type: Types::TEXT)]
    private string $publicKey;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $fingerprint;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, ManagedSshKeySource $source, string $publicKey, string $fingerprint, ?\DateTimeImmutable $now = null)
    {
        $this->name = $name;
        $this->source = $source;
        $this->publicKey = $publicKey;
        $this->fingerprint = $fingerprint;
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function source(): ManagedSshKeySource
    {
        return $this->source;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
