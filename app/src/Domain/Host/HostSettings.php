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
    /** Jobs of different instances that may run at once (FLE-209). 1 = one after another, the behaviour before the setting. */
    public const int DEFAULT_MAX_CONCURRENT_JOBS = 1;
    public const int MAX_MAX_CONCURRENT_JOBS = 8;

    /** The largest instance cap the host page accepts (FLE-203). */
    public const int MAX_MAX_INSTANCES = 10000;

    /** Threads kept for the OS, Docker, Caddy, php-fpm and the Fleet workers (capacity allotments, FLE-197). */
    public const int DEFAULT_CPU_RESERVE_THREADS = 2;
    /** Instances that may exist on the host at once (the dispatch gate's cap). */
    public const int DEFAULT_MAX_INSTANCES = 25;

    /** Browser uploads: the default cap per file, 2 GiB (design spec section 17). Bigger files go in over SSH. */
    public const int DEFAULT_ASSET_UPLOAD_MAX_BYTES = 2_147_483_648;
    /** The host settings page edits the cap in MiB, up to 1 TiB. */
    public const int MAX_ASSET_UPLOAD_MEBIBYTES = 1_048_576;

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

    /** How many jobs (of different instances) the worker runs at the same time. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_MAX_CONCURRENT_JOBS])]
    private int $maxConcurrentJobs = self::DEFAULT_MAX_CONCURRENT_JOBS;

    /** CPU threads kept back from the allotments (allocatable = threads - reserve). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_CPU_RESERVE_THREADS])]
    private int $cpuReserveThreads = self::DEFAULT_CPU_RESERVE_THREADS;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_MAX_INSTANCES])]
    private int $maxInstances = self::DEFAULT_MAX_INSTANCES;

    /** Whether ddev-router mirrors the catalogue's public ports (and :443) inside the Docker network (FLE-29, FLE-32). */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $portMirror = true;

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

    /** FLE-299: instance network isolation of this host; off until an admin turns it on, never exported or imported. */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: IsolationMode::class, options: ['default' => 'off'])]
    private IsolationMode $isolationMode = IsolationMode::Off;

    /** FLE-299: extra host TCP ports the containers may reach in enforce mode (JSON list of ints), the escape hatch of the allow-list. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => '[]'])]
    private string $isolationHostPorts = '[]';

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $domain, \DateTimeImmutable $now)
    {
        $this->domain = $domain;
        $this->createdAt = $this->updatedAt = $now;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function changeDomain(string $domain, \DateTimeImmutable $now): void
    {
        $this->domain = $domain;
        $this->touch($now);
    }

    public function acmeEmail(): ?string
    {
        return $this->acmeEmail;
    }

    public function changeAcmeEmail(?string $acmeEmail, \DateTimeImmutable $now): void
    {
        $this->acmeEmail = $acmeEmail;
        $this->touch($now);
    }

    public function tlsMode(): TlsMode
    {
        return $this->tlsMode;
    }

    public function changeTlsMode(TlsMode $tlsMode, \DateTimeImmutable $now): void
    {
        $this->tlsMode = $tlsMode;
        $this->touch($now);
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
    public function storeSetupToken(string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $tokenHash)) {
            throw new \InvalidArgumentException('The setup token hash must be a sha256 hex digest.');
        }
        $this->setupTokenHash = $tokenHash;
        $this->setupTokenExpiresAt = $expiresAt;
        $this->touch($now);
    }

    public function clearSetupToken(\DateTimeImmutable $now): void
    {
        $this->setupTokenHash = null;
        $this->setupTokenExpiresAt = null;
        $this->touch($now);
    }

    public function setupCompletedAt(): ?\DateTimeImmutable
    {
        return $this->setupCompletedAt;
    }

    public function markSetupComplete(\DateTimeImmutable $at): void
    {
        $this->setupCompletedAt = $at;
        $this->touch($at);
    }

    /**
     * Setup is open again (fleet:setup:reset). The caller mints a new token; the old one is gone.
     */
    public function reopenSetup(\DateTimeImmutable $now): void
    {
        $this->setupCompletedAt = null;
        $this->clearSetupToken($now);
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

    public function changeAssetUploadMaxBytes(int $bytes, \DateTimeImmutable $now): void
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('The upload cap must be at least one byte.');
        }
        $this->assetUploadMaxBytes = $bytes;
        $this->touch($now);
    }

    public function backupRetentionDays(): int
    {
        return $this->backupRetentionDays;
    }

    public function changeBackupRetentionDays(int $days, \DateTimeImmutable $now): void
    {
        if ($days < 1 || $days > self::MAX_BACKUP_RETENTION_DAYS) {
            throw new \InvalidArgumentException(\sprintf('The backup retention must be between 1 and %d days.', self::MAX_BACKUP_RETENTION_DAYS));
        }
        $this->backupRetentionDays = $days;
        $this->touch($now);
    }

    public function maxConcurrentJobs(): int
    {
        return $this->maxConcurrentJobs;
    }

    public function changeMaxConcurrentJobs(int $jobs, \DateTimeImmutable $now): void
    {
        if ($jobs < 1 || $jobs > self::MAX_MAX_CONCURRENT_JOBS) {
            throw new \InvalidArgumentException(\sprintf('The job concurrency must be between 1 and %d.', self::MAX_MAX_CONCURRENT_JOBS));
        }
        $this->maxConcurrentJobs = $jobs;
        $this->touch($now);
    }

    public function cpuReserveThreads(): int
    {
        return $this->cpuReserveThreads;
    }

    public function maxInstances(): int
    {
        return $this->maxInstances;
    }

    public function changeCapacity(int $cpuReserveThreads, int $maxInstances, \DateTimeImmutable $now): void
    {
        if ($cpuReserveThreads < 0 || $cpuReserveThreads > 32767) {
            throw new \InvalidArgumentException('The CPU reserve must be between 0 and 32767 threads.');
        }
        if ($maxInstances < 1) {
            throw new \InvalidArgumentException('The instance cap must be at least 1.');
        }
        $this->cpuReserveThreads = $cpuReserveThreads;
        $this->maxInstances = $maxInstances;
        $this->touch($now);
    }

    public function isolationMode(): IsolationMode
    {
        return $this->isolationMode;
    }

    public function changeIsolationMode(IsolationMode $mode, \DateTimeImmutable $now): void
    {
        $this->isolationMode = $mode;
        $this->touch($now);
    }

    /**
     * @return list<int>
     */
    public function isolationHostPorts(): array
    {
        $ports = json_decode($this->isolationHostPorts, true);

        return \is_array($ports) ? array_values(array_filter($ports, static fn (mixed $p): bool => \is_int($p) && $p >= 1 && $p <= 65535)) : [];
    }

    /**
     * @param list<int> $ports
     */
    public function changeIsolationHostPorts(array $ports, \DateTimeImmutable $now): void
    {
        foreach ($ports as $port) {
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('A host port must be between 1 and 65535.');
            }
        }
        $ports = array_values(array_unique($ports));
        sort($ports);
        $this->isolationHostPorts = json_encode($ports, \JSON_THROW_ON_ERROR);
        $this->touch($now);
    }

    public function portMirror(): bool
    {
        return $this->portMirror;
    }

    public function changePortMirror(bool $enabled, \DateTimeImmutable $now): void
    {
        $this->portMirror = $enabled;
        $this->touch($now);
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
    public function changeGitIdentity(?string $name, ?string $email, \DateTimeImmutable $now): void
    {
        $this->gitUserName = self::blankToNull($name);
        $this->gitUserEmail = self::blankToNull($email);
        $this->touch($now);
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
    public function changeNotificationMail(?string $smtpHost, ?int $smtpPort, ?string $smtpUser, ?string $mailFrom, ?string $mailTo, \DateTimeImmutable $now): void
    {
        if (null !== $smtpPort && ($smtpPort < 1 || $smtpPort > 65535)) {
            throw new \InvalidArgumentException('The SMTP port must be between 1 and 65535.');
        }
        $this->smtpHost = self::blankToNull($smtpHost);
        $this->smtpPort = $smtpPort;
        $this->smtpUser = self::blankToNull($smtpUser);
        $this->mailFrom = self::blankToNull($mailFrom);
        $this->mailTo = self::blankToNull($mailTo);
        $this->touch($now);
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

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
