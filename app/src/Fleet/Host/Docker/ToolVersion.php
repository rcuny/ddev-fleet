<?php

declare(strict_types=1);

namespace App\Fleet\Host\Docker;

final readonly class ToolVersion
{
    public function __construct(
        public string $label,
        public ?string $version,
        public string $minimum,
        public bool $ok,
    ) {
    }
}
