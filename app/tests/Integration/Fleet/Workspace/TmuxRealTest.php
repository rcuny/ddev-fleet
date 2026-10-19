<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Workspace;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Workspace\Pane;
use App\Fleet\Workspace\Tmux;
use PHPUnit\Framework\TestCase;

/**
 * Against a real tmux, when there is one (skipped otherwise): `new-window ... <php> <console> fleet:tmux:sidebar --window
 * <id>` must exec its argv directly. tmux joins the trailing words into one command line for `sh -c`, so an id with a `-`
 * (every id has `--`) must survive that. The server runs in a private TMUX_TMPDIR, never the operator's.
 */
final class TmuxRealTest extends TestCase
{
    private const string ID = 'acme--fle-80-x';

    private string $root;

    protected function setUp(): void
    {
        $which = trim((string) shell_exec('command -v tmux 2>/dev/null'));
        if ('' === $which) {
            self::markTestSkipped('tmux is not installed.');
        }
        $this->root = sys_get_temp_dir().'/fleet-tmux-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/tmux', 0o700, true);
        mkdir($this->root.'/srv/instances/'.self::ID, 0o750, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            shell_exec(\sprintf('TMUX_TMPDIR=%s tmux -L fleet kill-server 2>/dev/null', escapeshellarg($this->root.'/tmux')));
            shell_exec('rm -rf '.escapeshellarg($this->root));
        }
    }

    public function testTheSidebarCommandReceivesItsArgumentsUnchangedAndKeysReachTheShell(): void
    {
        // Stands in for `php bin/console`: records the argv it was exec'd with, one per line.
        $stub = $this->root.'/php';
        $seen = $this->root.'/argv.txt';
        file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($seen)."\nexec sleep 30\n");
        chmod($stub, 0o755);

        $runner = new class(new ProcessCommandRunner(), $this->root.'/tmux') implements CommandRunner {
            public function __construct(private readonly ProcessCommandRunner $inner, private readonly string $tmpdir)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                return $this->inner->run($argv, $cwd, ['TMUX_TMPDIR' => $this->tmpdir] + $env, $onOutput, $timeout);
            }
        };
        $paths = new FleetPaths($this->root.'/srv', $this->root.'/caddy');
        $tmux = new Tmux($runner, $paths, '/opt/ddev-fleet/app/bin/console', $stub);
        $dir = $paths->instanceDir(self::ID);

        self::assertTrue($tmux->ensureWindow(self::ID, $dir));
        for ($i = 0; $i < 50 && !is_file($seen); ++$i) {
            usleep(100_000);
        }

        self::assertSame(
            "/opt/ddev-fleet/app/bin/console\nfleet:tmux:sidebar\n--window\n".self::ID."\n",
            (string) @file_get_contents($seen),
            'the window id reached the command as one untouched argument',
        );
        self::assertContains(self::ID, $tmux->listWindows());

        $tmux->sendKeys(self::ID, Pane::Tty1, 'printf "%s" ok > '.$this->root.'/typed.txt');
        for ($i = 0; $i < 50 && !is_file($this->root.'/typed.txt'); ++$i) {
            usleep(100_000);
        }
        self::assertSame('ok', (string) @file_get_contents($this->root.'/typed.txt'), 'the line was typed into the tty1 shell and run');

        self::assertTrue($tmux->closeWindow(self::ID));
        self::assertNotContains(self::ID, $tmux->listWindows());
    }
}
