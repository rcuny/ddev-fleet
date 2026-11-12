<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\IsolationMode;

/**
 * FLE-299: the result of one IsolationConverger pass. `error` is null on success; `applied` says whether a ruleset was installed.
 */
final readonly class IsolationPass
{
    /**
     * @param list<ResolvedLink> $links
     */
    public function __construct(
        public IsolationMode $mode,
        public array $links = [],
        public bool $applied = false,
        public ?string $error = null,
    ) {
    }

    public function ok(): bool
    {
        return null === $this->error;
    }
}
