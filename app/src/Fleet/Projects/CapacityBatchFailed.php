<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * A batch of allotment changes was refused as a whole (nothing was written): the first error of each field, by project.
 */
final class CapacityBatchFailed extends \DomainException
{
    /**
     * @param array<string, array<string, string>> $errors project name => field (cpu_threads, slots) => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid allotments for: '.implode(', ', array_keys($errors)).'.');
    }
}
