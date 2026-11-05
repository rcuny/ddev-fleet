<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

/**
 * A command started by a BackgroundRunner and not waited for.
 */
interface BackgroundProcess
{
    public function isRunning(): bool;

    /** The exit code once the command has ended (null while it runs, or when it was killed by a signal). */
    public function exitCode(): ?int;

    /** Blocks until the command ends. */
    public function wait(): void;
}
