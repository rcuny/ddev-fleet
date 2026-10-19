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
    public const int DEFAULT_BACKUP_RETENTION_DAYS = 14;
    public const int MAX_BACKUP_RETENTION_DAYS = 3650;

    /** Browser uploads: the default cap per file, 2 GiB (design spec section 17). Bigger files go in over SSH. */
    public const int DEFAULT_ASSET_UPLOAD_MAX_BYTES = 2_147_483_648;

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

    // INTEGER holds 64 bits in SQLite; the PHP side is an int on the 64-bit builds the app requires.
    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_ASSET_UPLOAD_MAX_BYTES])]
    private int $assetUploadMaxBytes = self::DEFAULT_ASSET_UPLOAD_MAX_BYTES;

    /** Days a database backup is kept (fleet:backup:create prunes older ones). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_BACKUP_RETENTION_DAYS])]
    private int $backupRetentionDays = self::DEFAULT_BACKUP_RETENTION_DAYS;

    /** Default identity for commits the fleet makes in instances (the git bot). */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $gitUserName = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $gitUserEmail = null;

    // Notification e-mail. Non-secret fields only: the SMTP password lives in SecretStore.
    #[ORM\Column(type: Types::STRING, length: 253, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $smtpPort = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $smtpUser = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $mailFrom = null;

    #[ORM\Column(type: Types::STRING, length: 254, nullable: true)]
    private ?string $mailTo = null;

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

    /**
     * Setup is open again (fleet:setup:reset). The caller mints a new token; the old one is gone.
     */
    public function reopenSetup(): void
    {
        $this->setupCompletedAt = null;
        $this->clearSetupToken();
    }

    public function isSetupComplete(): bool
    {
        return null !== $this->setupCompletedAt;
    }

    /**
     * The largest file the browser may upload into a project's assets, in bytes.
     */
    public function assetUploadMaxBytes(): int
    {
        return $this->assetUploadMaxBytes;
    }

    public function changeAssetUploadMaxBytes(int $bytes): void
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('The upload cap must be at least one byte.');
        }
        $this->assetUploadMaxBytes = $bytes;
        $this->touch();
    }

    public function backupRetentionDays(): int
    {
        return $this->backupRetentionDays;
    }

    public function changeBackupRetentionDays(int $days): void
    {
        if ($days < 1 || $days > self::MAX_BACKUP_RETENTION_DAYS) {
            throw new \InvalidArgumentException(\sprintf('The backup retention must be between 1 and %d days.', self::MAX_BACKUP_RETENTION_DAYS));
        }
        $this->backupRetentionDays = $days;
        $this->touch();
    }

    public function gitUserName(): ?string
    {
        return $this->gitUserName;
    }

    public function gitUserEmail(): ?string
    {
        return $this->gitUserEmail;
    }

    /**
     * The validation of the values (the same rule as a project's git identity) is the caller's job; null clears one.
     */
    public function changeGitIdentity(?string $name, ?string $email): void
    {
        $this->gitUserName = self::blankToNull($name);
        $this->gitUserEmail = self::blankToNull($email);
        $this->touch();
    }

    public function smtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function smtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function smtpUser(): ?string
    {
        return $this->smtpUser;
    }

    public function mailFrom(): ?string
    {
        return $this->mailFrom;
    }

    public function mailTo(): ?string
    {
        return $this->mailTo;
    }

    /**
     * The non-secret notification e-mail settings (the SMTP password is a SecretStore secret). Blank strings become null.
     */
    public function changeNotificationMail(?string $smtpHost, ?int $smtpPort, ?string $smtpUser, ?string $mailFrom, ?string $mailTo): void
    {
        if (null !== $smtpPort && ($smtpPort < 1 || $smtpPort > 65535)) {
            throw new \InvalidArgumentException('The SMTP port must be between 1 and 65535.');
        }
        $this->smtpHost = self::blankToNull($smtpHost);
        $this->smtpPort = $smtpPort;
        $this->smtpUser = self::blankToNull($smtpUser);
        $this->mailFrom = self::blankToNull($mailFrom);
        $this->mailTo = self::blankToNull($mailTo);
        $this->touch();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}
