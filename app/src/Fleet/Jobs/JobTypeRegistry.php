<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class JobTypeRegistry
{
    /** @var array<string, JobType> */
    private array $types = [];

    /**
     * @param iterable<JobType> $types
     */
    public function __construct(#[AutowireIterator('fleet.job_type')] iterable $types)
    {
        foreach ($types as $type) {
            if (isset($this->types[$type->name()])) {
                throw new \LogicException(\sprintf('The job type "%s" is registered twice.', $type->name()));
            }
            $this->types[$type->name()] = $type;
        }
    }

    public function get(string $name): ?JobType
    {
        return $this->types[$name] ?? null;
    }
}
