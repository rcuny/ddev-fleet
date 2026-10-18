<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs real host commands via symfony/process in array form (never a shell).
 */
final class ProcessCommandRunner implements CommandRunner
{
    public function run(
        array $argv,
        ?string $cwd = null,
        array $env = [],
        ?callable $onOutput = null,
        ?float $timeout = null,
    ): CommandResult {
        if ([] === $argv) {
            throw new CommandRunnerException('Cannot run an empty command.');
        }
        if (null !== $cwd && !is_dir($cwd)) {
            throw new CommandRunnerException(\sprintf('Working directory "%s" does not exist.', $cwd));
        }

        $process = new Process($argv, $cwd, $env ?: null, null, $timeout);
        $started = microtime(true);
        $callback = null === $onOutput
            ? null
            : static function (string $type, string $chunk) use ($onOutput): void {
                $onOutput(Process::ERR === $type ? 'stderr' : 'stdout', $chunk);
            };

        try {
            $process->start($callback);
            $process->wait();
        } catch (ProcessTimedOutException $e) {
            throw new CommandTimedOut(\sprintf('Command timed out after %s seconds: %s', $timeout ?? 0, $argv[0]), $process->getOutput(), $process->getErrorOutput(), $e);
        } catch (ProcessStartFailedException $e) {
            return new CommandResult(127, '', $argv[0].': command not found', microtime(true) - $started);
        }

        return new CommandResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
            microtime(true) - $started,
        );
    }
}
