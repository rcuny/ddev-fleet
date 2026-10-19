<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * What a deploy request asks for. A null branch, template or label falls back to the project's defaults.
 */
final readonly class DeployInput
{
    public function __construct(
        public string $project,
        public ?string $template = null,
        public ?string $branch = null,
        public ?string $label = null,
        public int $count = 1,
        public bool $force = false,
        public bool $parallel = false,
        public bool $skipDiskCheck = false,
    ) {
    }
}
