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

    /** @var list<array{prefix: list<string>, result: CommandResult}> */
    private array $rules = [];

    /**
     * Script the result for the next call whose argv starts with $argvPrefix.
     * Rules are tried in registration order and each is consumed once.
     *
     * @param list<string> $argvPrefix
     */
    public function expect(array $argvPrefix, CommandResult $result): self
    {
        $this->rules[] = ['prefix' => $argvPrefix, 'result' => $result];

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
            if (null !== $onOutput) {
                self::replay($onOutput, 'stdout', $result->stdout);
                self::replay($onOutput, 'stderr', $result->stderr);
            }

            return $result;
        }

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
