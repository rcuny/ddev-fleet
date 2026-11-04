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

    private const string TAB = "\t";

    /** A `list-panes` answer: id, left, top, width, window width, role, start command. */
    private static function pane(string $id, int $left, int $width, string $role = '', string $command = 'bash', int $top = 0): string
    {
        return implode(self::TAB, [$id, $left, $top, $width, 200, $role, $command])."\n";
    }

    /**
     * @return list<list<string>>
     */
    private function tmuxCalls(string $verb): array
    {
        return array_map(static fn ($call): array => $call->argv, $this->runner->callsMatching(['tmux', '-L', 'fleet', $verb]));
    }

    public function testEnsureSessionCreatesItWithSettingsAndAWholeGeneralWindow(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(1, '', "can't find session: fleet"));
        // heal(general): one shell -> tty2 split -> sidebar split
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%0', 0, 200)));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%0', 0, 200)));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%0', 0, 99).self::pane('%1', 100, 100)));

        self::assertTrue($this->tmux->ensureSession());

        $argvs = $this->argvs();
        self::assertSame(['tmux', '-L', 'fleet', 'new-session', '-d', '-s', 'fleet', '-n', 'general', '-c', '/srv/fleet', '-x', '300', '-y', '80'], $argvs[1]);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-t', '=fleet:', 'mouse', 'on'], $argvs);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-t', '=fleet:', 'default-size', '300x80'], $argvs);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-t', '=fleet:', 'window-size', 'latest'], $argvs);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-s', 'set-clipboard', 'on'], $argvs);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-t', '=fleet:', 'window-status-current-style', 'reverse,bold'], $argvs);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-w', '-t', '=fleet:=general', '@fleet_managed', '1'], $argvs);
        self::assertCount(2, $this->tmuxCalls('split-window'), 'tty2 and the sidebar');
    }

    public function testEnsureSessionDoesNothingWhenItExists(): void
    {
        self::assertFalse($this->tmux->ensureSession());
        self::assertSame([['tmux', '-L', 'fleet', 'has-session', '-t', '=fleet']], $this->argvs());
    }

    public function testApplySettingsBindsResetWithThePaneIdOnlyAndNoSecret(): void
    {
        $this->tmux->applySettings();

        $bind = $this->tmuxCalls('bind-key')[0];
        self::assertSame(['tmux', '-L', 'fleet', 'bind-key', 'R', 'run-shell', '-b'], \array_slice($bind, 0, 7));
        self::assertStringContainsString("'fleet:tmux:reset' '--pane' '#{pane_id}'", $bind[7]);
        self::assertStringNotContainsString('window_name', $bind[7], 'the window name is read back from tmux, never interpolated');
        self::assertStringContainsString("'/etc/ddev-fleet/fleet.env'", $bind[7]);
    }

    public function testANewWindowIsSidebar50ThenTty1ThenTty2SideBySideAndEqual(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%5', 0, 200))); // heal: first look
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%5', 0, 99).self::pane('%6', 100, 100))); // after tty2: two shells
        $this->runner->expect(['tmux', '-L', 'fleet', 'split-window'], new CommandResult(0));
        $this->runner->expect(['tmux', '-L', 'fleet', 'split-window'], new CommandResult(0, "%7\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%7', 0, 120, 'sidebar').self::pane('%5', 121, 40).self::pane('%6', 162, 38)));

        self::assertTrue($this->tmux->ensureWindow(self::ID, self::DIR));

        $splits = $this->tmuxCalls('split-window');
        self::assertSame(['tmux', '-L', 'fleet', 'split-window', '-h', '-d', '-t', '%5', '-c', self::DIR], $splits[0]);
        self::assertSame(['-h', '-b', '-f'], \array_slice($splits[1], 4, 3));
        self::assertSame(['-l', '50', '-d', '-P', '-F', '#{pane_id}', '-t', '%5', '-c', self::DIR], \array_slice($splits[1], 7, 10));
        $sidebar = $splits[1][17];
        self::assertStringStartsWith("'sh' '-c' ", $sidebar);
        self::assertStringContainsString("'/usr/bin/php8.4' '/opt/ddev-fleet/app/bin/console' 'fleet:tmux:sidebar' '--window' '".self::ID."'", $sidebar);
        self::assertStringContainsString("'/etc/ddev-fleet/fleet.env'", $sidebar, 'the sidebar gets the application environment from the env file');
        self::assertStringNotContainsString('APP_SECRET', $sidebar);
        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-p', '-t', '%7', '@fleet_role', 'sidebar'], $this->argvs());
        // width 200: sidebar 50, tty1 (200 - 52) / 2 = 74
        self::assertContains(['tmux', '-L', 'fleet', 'resize-pane', '-t', '%7', '-x', '50'], $this->argvs());
        self::assertContains(['tmux', '-L', 'fleet', 'resize-pane', '-t', '%5', '-x', '74'], $this->argvs());
        self::assertContains(['tmux', '-L', 'fleet', 'new-window', '-d', '-t', '=fleet:', '-n', self::ID, '-c', self::DIR], $this->argvs());
    }

    public function testASidebarThatCrashedLeavesAnErrorAndKeepsThePane(): void
    {
        $command = $this->tmux->sidebarCommand(self::ID);

        self::assertStringContainsString('while :; do sleep 3600; done', $command);
        self::assertStringContainsString('the sidebar exited', $command);
    }

    public function testEnsureWindowOnAWholeWindowChangesNothing(): void
    {
        $whole = self::pane('%7', 0, 50, 'sidebar').self::pane('%5', 51, 74).self::pane('%6', 126, 74);
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n".self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $whole));

        self::assertFalse($this->tmux->ensureWindow(self::ID, self::DIR));

        self::assertSame([], $this->tmuxCalls('split-window'));
        self::assertSame([], $this->tmuxCalls('resize-pane'));
        self::assertSame([], $this->tmuxCalls('kill-pane'));
    }

    public function testAnOldTwoPaneWindowGetsASidebarWithoutKillingAnything(): void
    {
        // The 1.0 shape: the sidebar crashed, tty1 sits above tty2 in the same column.
        $old = self::pane('%5', 0, 200).self::pane('%6', 0, 200, '', 'bash', 12);
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n".self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $old));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $old));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%5', 0, 99).self::pane('%6', 100, 100)));
        $this->runner->expect(['tmux', '-L', 'fleet', 'split-window'], new CommandResult(0, "%9\n"));

        self::assertFalse($this->tmux->ensureWindow(self::ID, self::DIR));

        self::assertContains(['tmux', '-L', 'fleet', 'move-pane', '-h', '-d', '-s', '%6', '-t', '%5'], $this->argvs());
        self::assertCount(1, $this->tmuxCalls('split-window'), 'only the sidebar was added');
        self::assertSame([], $this->tmuxCalls('kill-pane'));
        self::assertSame([], $this->tmuxCalls('kill-window'));
    }

    public function testAnUntaggedSidebarOfAnEarlierWindowIsRecognisedAndTagged(): void
    {
        $panes = self::pane('%7', 0, 50, '', "sh -c 'x' fleet:tmux:sidebar").self::pane('%5', 51, 74).self::pane('%6', 126, 74);
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $panes));

        $this->tmux->ensureWindow(self::ID, self::DIR);

        self::assertContains(['tmux', '-L', 'fleet', 'set-option', '-p', '-t', '%7', '@fleet_role', 'sidebar'], $this->argvs());
        self::assertSame([], $this->tmuxCalls('split-window'));
    }

    public function testAWindowCalledHostBecomesGeneral(): void
    {
        $whole = self::pane('%7', 0, 50, 'sidebar').self::pane('%5', 51, 74).self::pane('%6', 126, 74);
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $whole));

        self::assertFalse($this->tmux->ensureGeneral());

        self::assertContains(['tmux', '-L', 'fleet', 'rename-window', '-t', '=fleet:=host', 'general'], $this->argvs());
    }

    public function testManagedWindowsAreTheTaggedOnes(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\t1\n".self::ID."\t1\nscratch\t\nplain\n"));

        self::assertSame(['general', self::ID], $this->tmux->managedWindows());
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

    public function testSendKeysTypesTheLiteralCommandThenEnterIntoTheRightShell(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n".self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%7', 0, 50, 'sidebar').self::pane('%5', 51, 74).self::pane('%6', 126, 74)));
        $command = 'claude --model sonnet "/jira work FLE-73" && echo $HOME; ls -la';

        $this->tmux->sendKeys(self::ID, Pane::Tty2, $command);

        self::assertSame(['tmux', '-L', 'fleet', 'send-keys', '-t', '%6', '-l', '--', $command], $this->argvs()[2]);
        self::assertSame(['tmux', '-L', 'fleet', 'send-keys', '-t', '%6', 'Enter'], $this->argvs()[3]);
    }

    public function testSendKeysFindsTheShellsByPositionWhenTheSidebarIsGone(): void
    {
        // Two panes, no sidebar: .1 is tty2 here, but it is still the second shell.
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%5', 0, 100).self::pane('%6', 101, 99)));

        $this->tmux->sendKeys(self::ID, Pane::Tty1, 'ls');

        self::assertSame('%5', $this->argvs()[2][5]);
    }

    public function testSendKeysRefusesAWindowWithoutASecondShell(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, self::pane('%5', 0, 200)));

        $this->expectException(WorkspaceException::class);
        $this->tmux->sendKeys(self::ID, Pane::Tty2, 'ls');
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

    public function testResetRespawnsEveryPaneInPlaceAndNeverKillsTheWindow(): void
    {
        $whole = self::pane('%7', 0, 50, 'sidebar').self::pane('%5', 51, 74).self::pane('%6', 126, 74);
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n".self::ID."\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $whole));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $whole));
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $whole));

        $this->tmux->reset(self::ID);

        $respawn = $this->tmuxCalls('respawn-pane');
        self::assertCount(3, $respawn);
        self::assertSame(['tmux', '-L', 'fleet', 'respawn-pane', '-k', '-t', '%7', '-c', self::DIR], \array_slice($respawn[0], 0, 9));
        self::assertStringContainsString("'fleet:tmux:sidebar'", $respawn[0][9], 'the sidebar is restarted with the env wrapper');
        self::assertSame(['tmux', '-L', 'fleet', 'respawn-pane', '-k', '-t', '%5', '-c', self::DIR], $respawn[1]);
        self::assertSame([], $this->tmuxCalls('kill-window'));
        self::assertSame([], $this->tmuxCalls('new-window'));
    }

    public function testResetOfAMissingWindowCreatesIt(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));

        $this->tmux->reset(self::ID);

        self::assertCount(1, $this->tmuxCalls('new-window'));
    }

    public function testResetWorksForTheGeneralWindow(): void
    {
        $whole = self::pane('%7', 0, 50, 'sidebar').self::pane('%5', 51, 74).self::pane('%6', 126, 74);
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));
        for ($i = 0; $i < 3; ++$i) {
            $this->runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $whole));
        }

        $this->tmux->reset('general');

        self::assertSame(['tmux', '-L', 'fleet', 'respawn-pane', '-k', '-t', '%5', '-c', '/srv/fleet'], $this->tmuxCalls('respawn-pane')[1]);
    }

    public function testWindowOfPaneAcceptsOnlyAPaneId(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'display-message'], new CommandResult(0, "acme--dev\n"));

        self::assertSame('acme--dev', $this->tmux->windowOfPane('%12'));
        self::assertNull($this->tmux->windowOfPane('%12; rm -rf /'));
        self::assertCount(1, $this->tmuxCalls('display-message'));
    }

    public function testAFailingTmuxCallThrowsWithItsStderrOnly(): void
    {
        $this->runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));
        $this->runner->expect(['tmux', '-L', 'fleet', 'new-window'], new CommandResult(1, '', 'duplicate window'));

        try {
            $this->tmux->ensureWindow(self::ID, self::DIR);
            self::fail('expected a failure');
        } catch (WorkspaceException $e) {
            self::assertStringContainsString('duplicate window', $e->getMessage());
        }
    }
}
