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

    /** @var array<string, list<array{id: string, role: string}>> the panes of each window, left to right */
    public array $panes = [];

    /** @var list<string> windows tagged @fleet_managed */
    public array $managed = [];

    private int $nextPane = 0;

    /** @var list<array{target: string, keys: string, pane: string}> what send-keys -l typed, in order */
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
            case 'new-window':
                $name = $args[array_search('-n', $args, true) + 1];
                $this->windows[] = $name;
                $this->panes[$name] = [['id' => '%'.$this->nextPane++, 'role' => '']];

                return new CommandResult(0);
            case 'kill-window':
                $name = substr((string) strrchr($args[2], '='), 1);
                $this->windows = array_values(array_diff($this->windows, [$name]));
                unset($this->panes[$name]);
                $this->managed = array_values(array_diff($this->managed, [$name]));

                return new CommandResult(0);
            case 'list-windows':
                return new CommandResult(0, implode("\n", array_map(fn (string $w): string => str_contains(implode(' ', $args), 'fleet_managed') ? $w."\t".(\in_array($w, $this->managed, true) ? '1' : '') : $w, $this->windows))."\n");
            case 'set-option':
                if ('-w' === $args[1] && '@fleet_managed' === $args[4]) {
                    $this->managed[] = substr((string) strrchr($args[3], '='), 1);
                }
                if ('-p' === $args[1] && '@fleet_role' === $args[4]) {
                    foreach ($this->panes as $name => $panes) {
                        foreach ($panes as $i => $pane) {
                            if ($pane['id'] === $args[3]) {
                                $this->panes[$name][$i] = ['id' => $pane['id'], 'role' => $args[5]];
                            }
                        }
                    }
                }

                return new CommandResult(0);
            case 'list-panes':
                $name = substr((string) strrchr($args[2], '='), 1);
                $out = '';
                foreach ($this->panes[$name] ?? [] as $i => $pane) {
                    $out .= implode("\t", [$pane['id'], $i * 60, 0, 'sidebar' === $pane['role'] ? 50 : 70, 200, $pane['role'], 'sidebar' === $pane['role'] ? 'sh -c fleet:tmux:sidebar' : 'bash'])."\n";
                }

                return new CommandResult(0, $out);
            case 'split-window':
                $target = $args[array_search('-t', $args, true) + 1];
                $id = '%'.$this->nextPane++;
                foreach ($this->panes as $name => $panes) {
                    foreach ($panes as $i => $pane) {
                        if ($pane['id'] === $target) {
                            $at = \in_array('-b', $args, true) ? 0 : $i + 1;
                            array_splice($this->panes[$name], $at, 0, [['id' => $id, 'role' => '']]);
                            break 2;
                        }
                    }
                }

                return new CommandResult(0, \in_array('-P', $args, true) ? $id."\n" : '');
            case 'send-keys':
                if (\in_array('-l', $args, true)) {
                    $this->typed[] = ['target' => $args[2], 'keys' => (string) end($args), 'pane' => $this->shellOf($args[2])];
                }

                return new CommandResult(0);
        }

        return new CommandResult(0);
    }

    private function shellOf(string $paneId): string
    {
        foreach ($this->panes as $panes) {
            $shells = array_values(array_filter($panes, static fn (array $p): bool => 'sidebar' !== $p['role']));
            foreach ($shells as $i => $pane) {
                if ($pane['id'] === $paneId) {
                    return 'tty'.($i + 1);
                }
            }
        }

        return 'unknown';
    }

    /**
     * @return list<string> the keys typed into one pane (`tty1` or `tty2`), in order
     */
    public function typedIn(string $pane): array
    {
        return array_values(array_map(
            static fn (array $t): string => $t['keys'],
            array_filter($this->typed, static fn (array $t): bool => $t['pane'] === $pane),
        ));
    }
}
