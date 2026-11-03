<?php

declare(strict_types=1);

namespace App\Fleet\Ddev;

/**
 * One line of `ddev list`: the project name, the status ddev reports ("running", "stopped", "paused", ...) and where
 * its checkout is.
 */
final readonly class DdevProject
{
    public function __construct(public string $name, public string $status, public string $approot)
    {
    }

    public function isRunning(): bool
    {
        return 'running' === $this->status;
    }
}
