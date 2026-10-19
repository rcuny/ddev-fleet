<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

/**
 * Dev/test fake: records every call, replays scripted results, executes nothing.
 */
final class RecordingCommandRunner implements CommandRunner
{
    /** @var list<RecordedCall> */
    private array $calls = [];

    /** @var list<array{prefix: list<string>, result: CommandResult, effect: (callable(list<string>, ?string): void)|null}> */
    private array $rules = [];

    /**
     * Script the result for the next call whose argv starts with $argvPrefix.
     * Rules are tried in registration order and each is consumed once.
     *
     * $effect, when given, runs on the match with the call's argv and cwd (for example to create the files the real
     * command would have created).
     *
     * @param list<string>                                 $argvPrefix
     * @param (callable(list<string>, ?string): void)|null $effect
     */
    public function expect(array $argvPrefix, CommandResult $result, ?callable $effect = null): self
    {
        $this->rules[] = ['prefix' => $argvPrefix, 'result' => $result, 'effect' => $effect];

        return $this;
    }

    public function run(
        array $argv,
        ?string $cwd = null,
        array $env = [],
        ?callable $onOutput = null,
        ?float $timeout = null,
    ): CommandResult {
        $this->calls[] = new RecordedCall($argv, $cwd, $env, $timeout);

        foreach ($this->rules as $i => $rule) {
            if (!self::startsWith($argv, $rule['prefix'])) {
                continue;
            }
            array_splice($this->rules, $i, 1);
            $result = $rule['result'];
            if (null !== $rule['effect']) {
                ($rule['effect'])($argv, $cwd);
            }
            if (null !== $onOutput) {
                self::replay($onOutput, 'stdout', $result->stdout);
                self::replay($onOutput, 'stderr', $result->stderr);
            }

            return $result;
        }

        self::pretendToClone($argv);

        return new CommandResult(0);
    }

    /**
     * @return list<RecordedCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param list<string> $prefix
     *
     * @return list<RecordedCall>
     */
    public function callsMatching(array $prefix): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (RecordedCall $call): bool => self::startsWith($call->argv, $prefix),
        ));
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->rules = [];
    }

    /**
     * An unscripted `git ... clone ... -- <url> <dir>` leaves a minimal checkout (`<dir>/.git/info`), so a deploy in dev
     * and test finds the clone it asked for.
     *
     * @param list<string> $argv
     */
    private static function pretendToClone(array $argv): void
    {
        if ('git' !== ($argv[0] ?? null) || !\in_array('clone', $argv, true)) {
            return;
        }
        $separator = array_search('--', $argv, true);
        $dir = false === $separator ? null : ($argv[$separator + 2] ?? null);
        if (null !== $dir && str_starts_with($dir, '/') && !is_dir($dir.'/.git/info')) {
            @mkdir($dir.'/.git/info', 0o750, true);
        }
    }

    /**
     * @param list<string> $argv
     * @param list<string> $prefix
     */
    private static function startsWith(array $argv, array $prefix): bool
    {
        return \array_slice($argv, 0, \count($prefix)) === $prefix;
    }

    /**
     * @param callable(string, string): void $onOutput
     */
    private static function replay(callable $onOutput, string $stream, string $data): void
    {
        if ('' === $data) {
            return;
        }
        foreach (preg_split('/(?<=\n)/', $data, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $onOutput($stream, $line);
        }
    }
}
