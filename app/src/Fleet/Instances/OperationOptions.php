<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

final readonly class OperationOptions
{
    public function __construct(
        public ?float $timeout = null,
        public bool $retryPortConflict = false,
        public bool $sequential = false,
        public ?string $template = null,
        public bool $force = false,
    ) {
    }
}
