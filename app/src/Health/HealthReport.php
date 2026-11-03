<?php

declare(strict_types=1);

namespace App\Health;

final readonly class HealthReport
{
    /**
     * @param array<string, bool> $checks check name => passed
     */
    public function __construct(public array $checks)
    {
    }

    public function isOk(): bool
    {
        return !\in_array(false, $this->checks, true);
    }

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isOk() ? 'ok' : 'fail',
            'checks' => array_map(static fn (bool $ok): string => $ok ? 'ok' : 'fail', $this->checks),
        ];
    }
}
