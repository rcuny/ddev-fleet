<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Domain\Instance\InstanceRepository;

final readonly class RepositoryInstanceIdSource implements InstanceIdSource
{
    public function __construct(private InstanceRepository $instances)
    {
    }

    public function ids(): array
    {
        return $this->instances->ids();
    }
}
