<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Upgrade\Check\HostRootCheck;
use App\Fleet\Upgrade\Check\NetworkCheck;
use App\Fleet\Upgrade\RepoUrl;

final class HostRootAndNetworkCheckTest extends CheckTestCase
{
    public function testSudoPresentIsQuiet(): void
    {
        self::assertSame([], new HostRootCheck(new RecordingCommandRunner())->check(self::plan()));
    }

    public function testMissingSudoBlocksAndIsNotOverridable(): void
    {
        $runner = new RecordingCommandRunner()->expect(['sudo'], new CommandResult(127));

        $results = new HostRootCheck($runner)->check(self::plan());

        self::assertSame([HostRootCheck::SUDO], self::ids($results));
        self::assertFalse($results[0]->overridable);
        self::assertNotSame('', $results[0]->remedy);
    }

    public function testReachableSourceIsQuiet(): void
    {
        self::assertSame([], new NetworkCheck(new RecordingCommandRunner(), new RepoUrl())->check(self::plan()));
    }

    public function testUnreachableSourceBlocks(): void
    {
        $runner = new RecordingCommandRunner()->expect(['git', 'ls-remote'], new CommandResult(128));

        $results = new NetworkCheck($runner, new RepoUrl('https://mirror.example/x.git'))->check(self::plan());

        self::assertSame([NetworkCheck::SOURCE], self::ids($results));
        self::assertStringContainsString('mirror.example', $results[0]->message);
        self::assertFalse($results[0]->overridable);
    }

    public function testATimeoutBlocksToo(): void
    {
        $runner = new class implements \App\Fleet\Shell\CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                throw new CommandTimedOut('timed out');
            }
        };

        self::assertSame([NetworkCheck::SOURCE], self::ids(new NetworkCheck($runner, new RepoUrl())->check(self::plan())));
    }
}
