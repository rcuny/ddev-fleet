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
 * Against a real tmux, when there is one (skipped otherwise): the 3-pane layout, the sidebar environment, healing and reset.
 * The server runs in a private TMUX_TMPDIR, never the operator's.
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

    private function runner(): CommandRunner
    {
        return new class(new ProcessCommandRunner(), $this->root.'/tmux') implements CommandRunner {
            public function __construct(private readonly ProcessCommandRunner $inner, private readonly string $tmpdir)
            {
            }

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                return $this->inner->run($argv, $cwd, ['TMUX_TMPDIR' => $this->tmpdir] + $env, $onOutput, $timeout);
            }
        };
    }

    private function wait(callable $done): void
    {
        for ($i = 0; $i < 50 && !$done(); ++$i) {
            usleep(100_000);
        }
    }

    /**
     * Stands in for `php bin/console`: records the environment and argv it was started with, then sleeps (or exits when
     * $crash, with a message on stderr).
     */
    private function stub(bool $crash = false): string
    {
        $stub = $this->root.'/php';
        $body = "#!/bin/sh\nprintf '%s\\n' \"\$APP_ENV\" \"\$@\" > ".escapeshellarg($this->root.'/seen.txt')."\n";
        $body .= $crash ? "echo 'ClassNotFoundError: DebugBundle' >&2\nexit 255\n" : "exec sleep 30\n";
        file_put_contents($stub, $body);
        chmod($stub, 0o755);

        return $stub;
    }

    private function envFile(): string
    {
        file_put_contents($this->root.'/fleet.env', "APP_ENV=prod\nFLEET_HOME=".$this->root."/srv\n");

        return $this->root.'/fleet.env';
    }

    /**
     * @return list<array{id: string, left: int, width: int, role: string}>
     */
    private function layout(Tmux $tmux, string $window): array
    {
        return array_map(static fn ($p): array => ['id' => $p->id, 'left' => $p->left, 'width' => $p->width, 'role' => $p->role], $tmux->panes($window));
    }

    public function testAWindowIsSidebar50Tty1Tty2SideBySideAndEqualAndKeysReachTheRightShell(): void
    {
        $paths = new FleetPaths($this->root.'/srv', $this->root.'/caddy');
        $tmux = new Tmux($this->runner(), $paths, '/opt/ddev-fleet/app/bin/console', $this->stub(), $this->envFile());
        $dir = $paths->instanceDir(self::ID);

        self::assertTrue($tmux->ensureWindow(self::ID, $dir));
        $this->wait(static fn (): bool => true);

        $panes = $this->layout($tmux, self::ID);
        self::assertCount(3, $panes);
        self::assertSame('sidebar', $panes[0]['role']);
        self::assertSame(50, $panes[0]['width']);
        self::assertSame($panes[1]['width'], $panes[2]['width'], 'tty1 and tty2 are equal');
        self::assertLessThan($panes[2]['left'], $panes[1]['left'], 'side by side');
        self::assertGreaterThan(0, $panes[1]['left']);

        // the general window has the same shape
        $tmux->ensureGeneral();
        self::assertCount(3, $this->layout($tmux, 'general'));
        self::assertContains('general', $tmux->managedWindows());

        $tmux->sendKeys(self::ID, Pane::Tty2, 'printf "%s" right > '.$this->root.'/typed2.txt');
        $tmux->sendKeys(self::ID, Pane::Tty1, 'printf "%s" left > '.$this->root.'/typed1.txt');
        $this->wait(fn (): bool => is_file($this->root.'/typed1.txt') && is_file($this->root.'/typed2.txt'));
        self::assertSame('right', (string) @file_get_contents($this->root.'/typed2.txt'));
        self::assertSame('left', (string) @file_get_contents($this->root.'/typed1.txt'));

        // a second call heals nothing and creates nothing
        self::assertFalse($tmux->ensureWindow(self::ID, $dir));
        self::assertCount(3, $this->layout($tmux, self::ID));

        self::assertTrue($tmux->closeWindow(self::ID));
        self::assertNotContains(self::ID, $tmux->listWindows());
    }

    public function testTheSidebarRunsUnderTheApplicationEnvironmentFromTheEnvFile(): void
    {
        $paths = new FleetPaths($this->root.'/srv', $this->root.'/caddy');
        $tmux = new Tmux($this->runner(), $paths, '/opt/ddev-fleet/app/bin/console', $this->stub(), $this->envFile());

        $tmux->ensureWindow(self::ID, $paths->instanceDir(self::ID));
        $this->wait(fn (): bool => is_file($this->root.'/seen.txt') && '' !== (string) file_get_contents($this->root.'/seen.txt'));

        self::assertSame(
            "prod\n/opt/ddev-fleet/app/bin/console\nfleet:tmux:sidebar\n--window\n".self::ID."\n",
            (string) @file_get_contents($this->root.'/seen.txt'),
            'APP_ENV came from the env file and the window id arrived as one untouched argument',
        );
    }

    public function testASidebarThatCrashesKeepsItsPaneAndTheNumbering(): void
    {
        $paths = new FleetPaths($this->root.'/srv', $this->root.'/caddy');
        $tmux = new Tmux($this->runner(), $paths, '/opt/ddev-fleet/app/bin/console', $this->stub(true), $this->envFile());

        $tmux->ensureWindow(self::ID, $paths->instanceDir(self::ID));
        $this->wait(fn (): bool => is_file($this->root.'/seen.txt'));
        usleep(500_000);

        self::assertCount(3, $this->layout($tmux, self::ID), 'the dead sidebar did not take its pane with it');
        $capture = (string) shell_exec(\sprintf('TMUX_TMPDIR=%s tmux -L fleet capture-pane -p -t %s', escapeshellarg($this->root.'/tmux'), escapeshellarg($tmux->panes(self::ID)[0]->id)));
        self::assertStringContainsString('DebugBundle', $capture, 'the error stays visible');
        self::assertStringContainsString('the sidebar exited', $capture);

        $tmux->sendKeys(self::ID, Pane::Tty2, 'printf "%s" ok > '.$this->root.'/typed.txt');
        $this->wait(fn (): bool => is_file($this->root.'/typed.txt'));
        self::assertSame('ok', (string) @file_get_contents($this->root.'/typed.txt'));
    }

    public function testAnOldTwoPaneWindowIsHealedInPlaceKeepingItsShells(): void
    {
        $paths = new FleetPaths($this->root.'/srv', $this->root.'/caddy');
        $runner = $this->runner();
        $tmux = new Tmux($runner, $paths, '/opt/ddev-fleet/app/bin/console', $this->stub(), $this->envFile());
        $dir = $paths->instanceDir(self::ID);
        $tmux->ensureSession();
        // The 1.0 shape: tty1 above tty2, no sidebar, untagged.
        $runner->run(['tmux', '-L', 'fleet', 'new-window', '-d', '-t', '=fleet:', '-n', self::ID, '-c', $dir]);
        $runner->run(['tmux', '-L', 'fleet', 'split-window', '-v', '-d', '-t', '=fleet:='.self::ID.'.0', '-c', $dir]);
        $before = array_map(static fn ($p): string => $p->id, $tmux->panes(self::ID));
        self::assertCount(2, $before);

        self::assertFalse($tmux->ensureWindow(self::ID, $dir));

        $panes = $this->layout($tmux, self::ID);
        self::assertCount(3, $panes);
        self::assertSame('sidebar', $panes[0]['role']);
        self::assertSame([$panes[1]['id'], $panes[2]['id']], $before, 'the two shells survived, side by side now');
        self::assertSame($panes[1]['width'], $panes[2]['width']);
    }

    public function testResetRespawnsInPlaceAndKeepsTheTabIndex(): void
    {
        $paths = new FleetPaths($this->root.'/srv', $this->root.'/caddy');
        $tmux = new Tmux($this->runner(), $paths, '/opt/ddev-fleet/app/bin/console', $this->stub(), $this->envFile());
        $dir = $paths->instanceDir(self::ID);
        $tmux->ensureWindow(self::ID, $dir);
        $tmux->sendKeys(self::ID, Pane::Tty1, 'touch '.$this->root.'/old-shell');
        $this->wait(fn (): bool => is_file($this->root.'/old-shell'));
        $before = array_map(static fn ($p): string => $p->id, $tmux->panes(self::ID));

        $tmux->reset(self::ID);

        $after = $this->layout($tmux, self::ID);
        self::assertCount(3, $after);
        self::assertSame($before, array_column($after, 'id'), 'the same panes, respawned');
        self::assertContains(self::ID, $tmux->listWindows());
    }
}
