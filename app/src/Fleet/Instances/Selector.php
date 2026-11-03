<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * Which instances an operation applies to: explicit ids, or `all`, or a project and/or state.
 */
final readonly class Selector
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        public array $ids = [],
        public bool $all = false,
        public ?string $project = null,
        public ?string $state = null,
    ) {
    }
}
