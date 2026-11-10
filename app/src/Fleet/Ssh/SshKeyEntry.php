<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * One row of the "SSH keys" view (FLE-266): public information only.
 */
final readonly class SshKeyEntry
{
    /**
     * @param string       $id      `deploy`, `push` or the extra key's name
     * @param string       $kind    `deploy`, `push` or `extra`
     * @param string       $origin  `host` (deploy, push), `generated`, `imported` or `external` (a file placed by hand)
     * @param list<string> $usedBy  what uses it, in words (projects, `project/template`)
     * @param string|null  $problem why an extra key cannot be loaded now, null when it can
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $origin,
        public string $purpose,
        public ?string $publicKey,
        public ?string $fingerprint,
        public array $usedBy,
        public ?\DateTimeImmutable $createdAt,
        public ?string $problem = null,
    ) {
    }

    public function deletable(): bool
    {
        return 'extra' === $this->kind && \in_array($this->origin, ['generated', 'imported'], true) && [] === $this->usedBy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->id,
            'kind' => $this->kind,
            'origin' => $this->origin,
            'purpose' => $this->purpose,
            'fingerprint' => $this->fingerprint,
            'public_key' => $this->publicKey,
            'used_by' => $this->usedBy,
            'created_at' => $this->createdAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'problem' => $this->problem,
        ];
    }
}
