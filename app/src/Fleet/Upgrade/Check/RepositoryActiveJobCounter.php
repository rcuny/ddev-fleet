<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Domain\Job\JobRepository;

final readonly class RepositoryActiveJobCounter implements ActiveJobCounter
{
    public function __construct(private JobRepository $jobs)
    {
    }

    public function count(): int
    {
        return $this->jobs->countActive();
    }
}
