<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * The certificate health of this host at one moment, as `fleet:host:tls-check` wrote it and the banner, the Host page
 * and `fleet host:tls-status` read it. Plain data: a JSON file with a fixed shape, nothing executable.
 */
final readonly class TlsStatus
{
    public const int WEEKLY_LIMIT = 50;

    /**
     * @param list<string>                                                  $headlines     what is wrong, one sentence each, most serious first (empty when ok)
     * @param list<string>                                                  $affectedNames hostnames Let's Encrypt refused or whose challenge failed
     * @param list<string>                                                  $errors        the last distinct error excerpts
     * @param list<array{name: string, expiresAt: ?string, daysLeft: ?int}> $certificates  the certificates probed
     */
    public function __construct(
        public TlsLevel $level,
        public \DateTimeImmutable $checkedAt,
        public ?string $domain = null,
        public array $headlines = [],
        public ?\DateTimeImmutable $rateLimitedSince = null,
        public ?\DateTimeImmutable $retryAfter = null,
        public int $rateLimitedCount = 0,
        public int $failedSolveCount = 0,
        public array $affectedNames = [],
        public array $errors = [],
        public array $certificates = [],
        public ?int $issuedLastWeek = null,
    ) {
    }

    public function isHealthy(): bool
    {
        return TlsLevel::Ok === $this->level;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level->label(),
            'checkedAt' => $this->checkedAt->format(\DATE_ATOM),
            'domain' => $this->domain,
            'headlines' => $this->headlines,
            'rateLimitedSince' => $this->rateLimitedSince?->format(\DATE_ATOM),
            'retryAfter' => $this->retryAfter?->format(\DATE_ATOM),
            'rateLimitedCount' => $this->rateLimitedCount,
            'failedSolveCount' => $this->failedSolveCount,
            'affectedNames' => $this->affectedNames,
            'errors' => $this->errors,
            'certificates' => $this->certificates,
            'issuedLastWeek' => $this->issuedLastWeek,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $level = match ($data['level'] ?? null) {
            'ok' => TlsLevel::Ok,
            'warning' => TlsLevel::Warning,
            'error' => TlsLevel::Error,
            default => null,
        };
        $checked = self::date($data['checkedAt'] ?? null);
        if (null === $level || null === $checked) {
            return null;
        }
        $certificates = [];
        foreach (\is_array($data['certificates'] ?? null) ? $data['certificates'] : [] as $cert) {
            if (\is_array($cert) && \is_string($cert['name'] ?? null)) {
                $days = $cert['daysLeft'] ?? null;
                $certificates[] = [
                    'name' => $cert['name'],
                    'expiresAt' => \is_string($cert['expiresAt'] ?? null) ? $cert['expiresAt'] : null,
                    'daysLeft' => \is_int($days) ? $days : null,
                ];
            }
        }
        $count = static fn (mixed $v): int => \is_int($v) && $v >= 0 ? $v : 0;

        return new self(
            $level,
            $checked,
            \is_string($data['domain'] ?? null) ? $data['domain'] : null,
            self::strings($data['headlines'] ?? null),
            self::date($data['rateLimitedSince'] ?? null),
            self::date($data['retryAfter'] ?? null),
            $count($data['rateLimitedCount'] ?? null),
            $count($data['failedSolveCount'] ?? null),
            self::strings($data['affectedNames'] ?? null),
            self::strings($data['errors'] ?? null),
            $certificates,
            \is_int($data['issuedLastWeek'] ?? null) ? $data['issuedLastWeek'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, '\is_string')) : [];
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
