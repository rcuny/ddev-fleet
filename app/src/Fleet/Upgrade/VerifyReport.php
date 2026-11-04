<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

final readonly class VerifyReport
{
    /**
     * @param list<VerifyResult> $results
     */
    public function __construct(public array $results)
    {
    }

    public function passed(): bool
    {
        foreach ($this->results as $result) {
            if ($result->critical && !$result->ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<VerifyResult>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->results, static fn (VerifyResult $r): bool => !$r->ok));
    }
}
