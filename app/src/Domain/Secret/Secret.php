<?php

declare(strict_types=1);

namespace App\Domain\Secret;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One encrypted value (libsodium secretbox). Only ciphertext and nonce are stored; the key lives in a
 * file under FLEET_HOME/keys.
 *
 * scopeId is '' for the fleet scope rather than NULL: SQLite treats NULLs as distinct in a unique
 * index, which would allow duplicate (scope, name) rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'secret')]
#[ORM\UniqueConstraint(name: 'uniq_secret_scope_name', columns: ['scope', 'scope_id', 'name'])]
class Secret
{
    public const string SCOPE_FLEET = 'fleet';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $scope;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $scopeId;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::BINARY)]
    private string $ciphertext;

    #[ORM\Column(type: Types::BINARY)]
    private string $nonce;

    #[ORM\Column(type: Types::INTEGER)]
    private int $keyVersion;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $updatedBy;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $scope,
        string $scopeId,
        string $name,
        string $ciphertext,
        string $nonce,
        int $keyVersion,
        string $updatedBy,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->scope = $scope;
        $this->scopeId = $scopeId;
        $this->name = $name;
        $this->ciphertext = $ciphertext;
        $this->nonce = $nonce;
        $this->keyVersion = $keyVersion;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function name(): string
    {
        return $this->name;
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

    public function updatedBy(): string
    {
        return $this->updatedBy;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function replace(string $ciphertext, string $nonce, int $keyVersion, string $updatedBy, ?\DateTimeImmutable $now = null): void
    {
        $this->ciphertext = $ciphertext;
        $this->nonce = $nonce;
        $this->keyVersion = $keyVersion;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
