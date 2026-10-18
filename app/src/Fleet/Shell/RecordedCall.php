<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

final readonly class RecordedCall
{
    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    public function __construct(
        public array $argv,
        public ?string $cwd,
        public array $env,
        public ?float $timeout,
    ) {
    }
}
