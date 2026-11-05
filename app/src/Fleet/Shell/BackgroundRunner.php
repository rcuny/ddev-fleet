<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

/**
 * The CommandRunner seam's other half: start a command and come back at once (FLE-209: the worker starts one child per
 * job). Same rules as CommandRunner (argv verbatim, never a shell), with one difference in the environment: the caller
 * passes the whole environment the child needs in $env, because a job child must be the app itself (DATABASE_URL, APP_ENV,
 * FLEET_HOME ...), not a host tool. Output is discarded: a job writes its own log.
 */
interface BackgroundRunner
{
    /**
     * @param list<string>          $argv program followed by its arguments
     * @param array<string, string> $env  merged over the allow-listed inherited environment
     *
     * @throws CommandRunnerException when the command cannot be started
     */
    public function start(array $argv, ?string $cwd = null, array $env = []): BackgroundProcess;
}
