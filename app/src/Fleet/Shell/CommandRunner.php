<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

/**
 * The only way the app runs host commands (ddev, git, docker, ...).
 *
 * Implementations must never go through a shell: argv is passed verbatim.
 */
interface CommandRunner
{
    /**
     * $timeout is in seconds; null or 0.0 means no timeout.
     * A missing binary gives exit 127, a non-executable one 126, a child killed by
     * signal N gives 128+N (stderr notes the signal).
     *
     * @param list<string>                          $argv     program followed by its arguments
     * @param array<string, string>                 $env      merged over the inherited environment
     * @param (callable(string, string): void)|null $onOutput receives ('stdout'|'stderr', chunk)
     *
     * @throws CommandTimedOut        when $timeout seconds elapse
     * @throws CommandRunnerException when the command cannot be started for a reason other than a missing binary
     */
    public function run(
        array $argv,
        ?string $cwd = null,
        array $env = [],
        ?callable $onOutput = null,
        ?float $timeout = null,
    ): CommandResult;
}
