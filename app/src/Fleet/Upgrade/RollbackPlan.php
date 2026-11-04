<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

/**
 * What a rollback (`--to` an older release) will do, decided before anything changes. Either it refuses (`refusals`,
 * each one with the way out) or it names the pre-upgrade backup to restore (`backup`, with `restoreDb`) and the moment
 * the data written afterwards starts to be lost (`since`).
 */
final readonly class RollbackPlan
{
    /**
     * @param list<string> $refusals
     */
    public function __construct(
        public array $refusals = [],
        public ?string $backup = null,
        public bool $restoreDb = false,
        public ?\DateTimeImmutable $since = null,
    ) {
    }

    public function ok(): bool
    {
        return [] === $this->refusals;
    }
}
