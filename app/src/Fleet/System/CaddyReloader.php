<?php

declare(strict_types=1);

namespace App\Fleet\System;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;

/**
 * Asks the running Caddy to pick up changed fleet snippets: validate the whole config first, then reload through the
 * admin API. Both run as the app's own user (no sudo): the main Caddyfile is world-readable and holds only
 * `{env.X}` placeholders, which validate leaves unresolved.
 */
final readonly class CaddyReloader
{
    private const float TIMEOUT = 30.0;

    /** Seconds validate and reload can take together at most (each has its own timeout). */
    public const float MAX_SECONDS = 2 * self::TIMEOUT;

    public function __construct(
        private CommandRunner $runner,
        private FleetPaths $paths,
    ) {
    }

    /**
     * @throws \RuntimeException with the end of Caddy's output when it rejects the config or cannot be reached
     */
    public function validateAndReload(): void
    {
        $this->run('validate', 'validate');
        $this->run('reload', 'reload');
    }

    private function run(string $subcommand, string $verb): void
    {
        $argv = ['caddy', $subcommand, '--config', $this->paths->caddyMainFile(), '--adapter', 'caddyfile'];
        try {
            $result = $this->runner->run($argv, timeout: self::TIMEOUT);
        } catch (CommandTimedOut $e) {
            throw new \RuntimeException(\sprintf('Caddy %s timed out.', $verb), 0, $e);
        } catch (CommandRunnerException $e) {
            throw new \RuntimeException(\sprintf('Caddy %s could not be started.', $verb), 0, $e);
        }

        if (!$result->isSuccess()) {
            throw new \RuntimeException(\sprintf('Caddy %s failed (exit %d): %s', $verb, $result->exitCode, OutputTail::of($result)));
        }
    }
}
