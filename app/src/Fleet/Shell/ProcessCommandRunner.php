<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs real host commands via symfony/process in array form (never a shell).
 */
final class ProcessCommandRunner implements CommandRunner
{
    /** Inherited by every child. Everything else in the app's environment (APP_SECRET, DATABASE_URL, ...) is not. */
    private const array INHERITED_NAMES = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TERM', 'USER', 'SSH_AUTH_SOCK'];

    /** Inherited by every child: the tools the runner exists to drive read their configuration from these. */
    private const array INHERITED_PREFIXES = ['DDEV_', 'DOCKER_', 'GIT_'];

    public function run(
        array $argv,
        ?string $cwd = null,
        array $env = [],
        ?callable $onOutput = null,
        ?float $timeout = null,
    ): CommandResult {
        $this->assertRunnable($argv);
        if (null !== $cwd && !is_dir($cwd)) {
            throw new CommandRunnerException(\sprintf('Working directory "%s" does not exist.', $cwd));
        }

        $process = new Process($argv, $cwd, $this->childEnvironment($env), null, $timeout);
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
        } catch (ProcessSignaledException $e) {
            $signal = $e->getSignal();

            return new CommandResult(
                128 + $signal,
                $process->getOutput(),
                $process->getErrorOutput().\sprintf('%s: terminated by signal %d', $argv[0], $signal),
                microtime(true) - $started,
            );
        } catch (ProcessStartFailedException $e) {
            return $this->startFailure($argv[0], $e, microtime(true) - $started);
        } finally {
            // Also covers an $onOutput callback that throws: never leave the child running.
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return new CommandResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
            microtime(true) - $started,
        );
    }

    /**
     * The child gets the allow-listed part of the app's environment plus the caller's $env; Symfony's Process
     * would otherwise copy everything, so every other inherited variable is explicitly removed (false).
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string|false>
     */
    private function childEnvironment(array $extra): array
    {
        $names = array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER));
        $env = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if (!self::isInherited($name)) {
                $env[$name] = false;
            }
        }

        return $extra + $env;
    }

    private static function isInherited(string $name): bool
    {
        if (\in_array($name, self::INHERITED_NAMES, true)) {
            return true;
        }
        foreach (self::INHERITED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $argv
     */
    private function assertRunnable(array $argv): void
    {
        if ([] === $argv) {
            throw new CommandRunnerException('Cannot run an empty command.');
        }
        if ('' === $argv[0]) {
            throw new CommandRunnerException('Cannot run a command with an empty program name.');
        }
        foreach ($argv as $index => $arg) {
            if (str_contains((string) $arg, "\0")) {
                throw new CommandRunnerException(\sprintf('Command argument %d contains a NUL byte.', $index));
            }
        }
    }

    private function startFailure(string $program, ProcessStartFailedException $e, float $duration): CommandResult
    {
        if (str_contains($program, '/')) {
            $found = file_exists($program);
            $executable = is_file($program) && is_executable($program);
        } else {
            $path = new ExecutableFinder()->find($program);
            $found = null !== $path;
            $executable = $found;
        }

        if (!$found) {
            return new CommandResult(127, '', $program.': command not found', $duration);
        }
        if (!$executable) {
            return new CommandResult(126, '', $program.': permission denied', $duration);
        }

        throw new CommandRunnerException(\sprintf('Could not start "%s": %s', $program, $e->getMessage()), 0, $e);
    }
}
