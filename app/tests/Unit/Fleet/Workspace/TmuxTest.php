<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Workspace;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Workspace\Pane;
use App\Fleet\Workspace\Tmux;
use App\Fleet\Workspace\WorkspaceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TmuxTest extends TestCase
{
    private const string ID = 'acme--dev';
    private const string DIR = '/srv/fleet/instances/acme--dev';

    private RecordingCommandRunner $runner;
    private Tmux $tmux;

    protected function setUp(): void
    {
        $this->runner = new RecordingCommandRunner();
        $this->tmux = new Tmux($this->runner, new FleetPaths('/srv/fleet', '/etc/caddy/fleet'), '/opt/ddev-fleet/app/bin/console');
    }

    /**
     * @return list<list<string>>
     */
    private function argvs(): array
    {
        return array_map(static fn ($call): array => $call->argv, $this->runner->calls());
    }

    public function testEnsureSessionCreatesItOnlyWhenMissing(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(1, '', "can't find session: fleet"));

        self::assertTrue($this->tmux->ensureSession());
        self::assertSame(
            [
                ['tmux', '-L', 'fleet', 'has-session', '-t', '=fleet'],
                ['tmux', '-L', 'fleet', 'new-session', '-d', '-s', 'fleet', '-n', 'host', '-c', '/srv/fleet'],
            ],
            $this->argvs(),
        );

        self::assertFalse($this->tmux->ensureSession());
        self::assertCount(3, $this->runner->calls(), 'only has-session ran the second time');
    }

    public function testEnsureWindowBuildsSidebarTty1AndTty2InTheInstanceDirectory(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(0));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));

        self::assertTrue($this->tmux->ensureWindow(self::ID, self::DIR));

        self::assertSame(
            [
                ['tmux', '-L', 'fleet', 'has-session', '-t', '=fleet'],
                ['tmux', '-L', 'fleet', 'list-windows', '-t', '=fleet', '-F', '#{window_name}'],
                ['tmux', '-L', 'fleet', 'new-window', '-d', '-t', '=fleet:', '-n', self::ID, '-c', self::DIR, '/usr/bin/php8.4', '/opt/ddev-fleet/app/bin/console', 'fleet:tmux:sidebar', '--window', self::ID],
                ['tmux', '-L', 'fleet', 'split-window', '-h', '-d', '-t', '=fleet:='.self::ID.'.0', '-c', self::DIR],
                ['tmux', '-L', 'fleet', 'split-window', '-v', '-d', '-t', '=fleet:='.self::ID.'.1', '-c', self::DIR],
            ],
            $this->argvs(),
        );
    }

    public function testEnsureWindowTwiceCreatesOneWindow(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n".self::ID."\n"), null);

        self::assertTrue($this->tmux->ensureWindow(self::ID, self::DIR));
        self::assertFalse($this->tmux->ensureWindow(self::ID, self::DIR));

        self::assertCount(1, $this->runner->callsMatching(['tmux', '-L', 'fleet', 'new-window']));
        self::assertCount(2, $this->runner->callsMatching(['tmux', '-L', 'fleet', 'split-window']));
    }

    public function testEnsureWindowRefusesAnotherDirectoryAndABadId(): void
    {
        foreach ([[self::ID, '/tmp'], [self::ID, self::DIR.'/../x'], ['bad id', '/srv/fleet/instances/bad id'], ['-oops--x', '/srv/fleet/instances/-oops--x']] as [$id, $dir]) {
            try {
                $this->tmux->ensureWindow($id, $dir);
                self::fail('expected a refusal for '.$id.' '.$dir);
            } catch (WorkspaceException) {
            }
        }
        self::assertSame([], $this->runner->calls(), 'nothing ran');
    }

    public function testListWindowsIsEmptyWithoutASession(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(1, '', 'no server running'));

        self::assertSame([], $this->tmux->listWindows());
    }

    public function testListWindowsReturnsTheNames(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\nacme--dev\n\n"));

        self::assertSame(['host', 'acme--dev'], $this->tmux->listWindows());
    }

    public function testSendKeysTypesTheLiteralCommandThenEnter(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n".self::ID."\n"));
        $command = 'claude --model sonnet "/jira work FLE-73" && echo $HOME; ls -la';

        $this->tmux->sendKeys(self::ID, Pane::Tty2, $command);

        self::assertSame(
            [
                ['tmux', '-L', 'fleet', 'list-windows', '-t', '=fleet', '-F', '#{window_name}'],
                ['tmux', '-L', 'fleet', 'send-keys', '-t', '=fleet:='.self::ID.'.2', '-l', '--', $command],
                ['tmux', '-L', 'fleet', 'send-keys', '-t', '=fleet:='.self::ID.'.2', 'Enter'],
            ],
            $this->argvs(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCommands(): iterable
    {
        yield 'newline' => ["ls\nrm -rf /"];
        yield 'carriage return' => ["ls\rid"];
        yield 'escape' => ["echo \e[2J"];
        yield 'tab' => ["ls\t-la"];
        yield 'nul' => ["ls\0"];
        yield 'delete' => ["ls\x7f"];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', Tmux::COMMAND_MAX + 1)];
    }

    #[DataProvider('refusedCommands')]
    public function testSendKeysRefusesControlCharactersAndNothingRuns(string $command): void
    {
        try {
            $this->tmux->sendKeys(self::ID, Pane::Tty1, $command);
            self::fail('expected a refusal');
        } catch (WorkspaceException $e) {
            self::assertStringNotContainsString('rm -rf', $e->getMessage());
        }
        self::assertSame([], $this->runner->calls());
    }

    public function testSendKeysRefusesTheSidebarAndAMissingWindow(): void
    {
        try {
            $this->tmux->sendKeys(self::ID, Pane::Sidebar, 'ls');
            self::fail('expected a refusal');
        } catch (WorkspaceException) {
        }
        self::assertSame([], $this->runner->calls());

        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));
        $this->expectException(WorkspaceException::class);
        $this->tmux->sendKeys(self::ID, Pane::Tty1, 'ls');
    }

    public function testCloseWindowKillsAnExistingWindowOnly(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n".self::ID."\n"));
        self::assertTrue($this->tmux->closeWindow(self::ID));
        self::assertSame(['tmux', '-L', 'fleet', 'kill-window', '-t', '=fleet:='.self::ID], $this->runner->calls()[1]->argv);

        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));
        self::assertFalse($this->tmux->closeWindow(self::ID));
        self::assertCount(1, $this->runner->callsMatching(['tmux', '-L', 'fleet', 'kill-window']));
    }

    public function testResetClosesAndRebuildsTheWindow(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n".self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));

        $this->tmux->reset(self::ID);

        self::assertCount(1, $this->runner->callsMatching(['tmux', '-L', 'fleet', 'kill-window']));
        self::assertCount(1, $this->runner->callsMatching(['tmux', '-L', 'fleet', 'new-window']));
    }

    public function testAFailingTmuxCallThrowsWithItsStderrOnly(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'new-window'], new CommandResult(1, '', 'duplicate window'));

        try {
            $this->tmux->ensureWindow(self::ID, self::DIR);
            self::fail('expected a failure');
        } catch (WorkspaceException $e) {
            self::assertStringContainsString('duplicate window', $e->getMessage());
        }
    }
}
