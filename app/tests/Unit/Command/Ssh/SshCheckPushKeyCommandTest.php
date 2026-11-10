<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\Ssh;

use App\Command\Ssh\SshCheckPushKeyCommand;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\PushKeyCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SshCheckPushKeyCommandTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle271-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/ssh', 0o700, true);
        touch($this->home.'/ssh/push_ed25519');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    private function tester(RecordingCommandRunner $runner): CommandTester
    {
        return new CommandTester(new SshCheckPushKeyCommand(new PushKeyCheck(new FleetPaths($this->home, $this->home.'/caddy'), $runner)));
    }

    public function testRegisteredExitsZero(): void
    {
        $t = $this->tester(new RecordingCommandRunner()->expect(['ssh'], new CommandResult(0, 'authenticated via ssh key.')));

        self::assertSame(Command::SUCCESS, $t->execute(['--host' => 'bitbucket.org', '--json' => true]));
        self::assertSame(['host' => 'bitbucket.org', 'state' => 'registered', 'ok' => true], array_intersect_key(json_decode($t->getDisplay(), true), ['host' => 1, 'state' => 1, 'ok' => 1]));
    }

    public function testNotRegisteredSaysSoAndExitsOne(): void
    {
        $t = $this->tester(new RecordingCommandRunner()->expect(['ssh'], new CommandResult(255, '', 'git@bitbucket.org: Permission denied (publickey).')));

        self::assertSame(Command::FAILURE, $t->execute([]));
        self::assertStringContainsString('push key is not registered on the git host bitbucket.org', $t->getDisplay());
    }

    public function testABadHostIsInvalidAndNothingRuns(): void
    {
        $runner = new RecordingCommandRunner();

        self::assertSame(Command::INVALID, $this->tester($runner)->execute(['--host' => '-oProxyCommand=x']));
        self::assertSame([], $runner->calls());
    }
}
