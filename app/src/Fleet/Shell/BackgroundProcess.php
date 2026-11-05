<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

/**
 * A command started by a BackgroundRunner and not waited for.
 */
interface BackgroundProcess
{
    public function isRunning(): bool;

    /** Blocks until the command ends. */
    public function wait(): void;
}
