<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\Jobs\JobLog;

/**
 * What a DeployStep sees: the instance, its project, the checkout, the context tokens and the job log. A step that
 * writes a file the project must not commit reports it through exclude().
 */
final class DeployContext
{
    /** @var list<string> */
    private array $excluded = [];

    /**
     * @param array<string, string> $tokens
     */
    public function __construct(
        public readonly Instance $instance,
        public readonly Project $project,
        public readonly string $dir,
        public readonly array $tokens,
        public readonly JobLog $log,
    ) {
    }

    public function exclude(string $relativePath): void
    {
        $this->excluded[] = $relativePath;
    }

    /**
     * @return list<string>
     */
    public function excluded(): array
    {
        return $this->excluded;
    }
}
