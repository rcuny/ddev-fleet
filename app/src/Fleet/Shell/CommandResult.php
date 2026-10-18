<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
        public float $durationSeconds = 0.0,
    ) {
    }

    public function isSuccess(): bool
    {
        return 0 === $this->exitCode;
    }
}
