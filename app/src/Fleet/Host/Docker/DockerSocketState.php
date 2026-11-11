<?php

declare(strict_types=1);

namespace App\Fleet\Host\Docker;

final readonly class DockerSocketState
{
    public function __construct(
        public string $path,
        public bool $ok,
        public ?int $mode,
        public ?string $owner,
        public ?string $group,
        public string $summary,
    ) {
    }
}
