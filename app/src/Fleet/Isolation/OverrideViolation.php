<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * One thing in an instance's `.ddev/` files that the deploy guard refuses (FLE-298): the file (relative to the checkout),
 * the key path inside it and a sentence saying why.
 */
final readonly class OverrideViolation
{
    public function __construct(
        public string $file,
        public string $key,
        public string $reason,
    ) {
    }

    public function message(): string
    {
        return \sprintf('%s: %s (%s)', $this->file, $this->key, $this->reason);
    }
}
