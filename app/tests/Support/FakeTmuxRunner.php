<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;

/**
 * A tmux that remembers: windows come and go with new-window and kill-window, and every literal send-keys is kept, so a
 * test can read back what was typed where. Any other program is not its business and answers success.
 */
final class FakeTmuxRunner implements CommandRunner
{
    /** @var list<string> */
    public array $windows = [];

    /** @var list<array{target: string, keys: string}> what send-keys -l typed, in order */
    public array $typed = [];

    /** @var list<list<string>> every argv, tmux or not */
    public array $argvs = [];

    /** Fail the tmux subcommand with this name (exit 1), or throw when $missing. */
    public ?string $failing = null;
    public bool $missing = false;

    public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
    {
        $this->argvs[] = $argv;
        if ('tmux' !== $argv[0]) {
            return new CommandResult(0);
        }
        if ($this->missing) {
            throw new CommandRunnerException('tmux is not installed');
        }
        $args = \array_slice($argv, 3);
        $command = $args[0] ?? '';
        if ($command === $this->failing) {
            return new CommandResult(1, '', 'boom');
        }

        switch ($command) {
            case 'has-session':
                return new CommandResult(0);
            case 'list-windows':
                return new CommandResult(0, implode("\n", $this->windows)."\n");
            case 'new-window':
                $this->windows[] = $args[array_search('-n', $args, true) + 1];

                return new CommandResult(0);
            case 'kill-window':
                $name = substr((string) strrchr($args[2], '='), 1);
                $this->windows = array_values(array_diff($this->windows, [$name]));

                return new CommandResult(0);
            case 'send-keys':
                if (\in_array('-l', $args, true)) {
                    $this->typed[] = ['target' => $args[2], 'keys' => (string) end($args)];
                }

                return new CommandResult(0);
        }

        return new CommandResult(0);
    }

    /**
     * @return list<string> the keys typed into one pane (`tty1` or `tty2`), in order
     */
    public function typedIn(string $pane): array
    {
        $index = ['tty1' => '.1', 'tty2' => '.2'][$pane];

        return array_values(array_map(
            static fn (array $t): string => $t['keys'],
            array_filter($this->typed, static fn (array $t): bool => str_ends_with($t['target'], $index)),
        ));
    }
}
