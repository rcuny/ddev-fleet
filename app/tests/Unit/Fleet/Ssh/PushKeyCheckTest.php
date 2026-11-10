<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\PushKeyCheck;
use App\Fleet\Ssh\PushKeyState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushKeyCheckTest extends TestCase
{
    private string $home;
    private FleetPaths $paths;
    private RecordingCommandRunner $runner;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle271-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/ssh', 0o700, true);
        $this->paths = new FleetPaths($this->home, '/etc/caddy/fleet');
        $this->runner = new RecordingCommandRunner();
    }

    protected function tearDown(): void
    {
        @unlink($this->home.'/ssh/push_ed25519');
        @rmdir($this->home.'/ssh');
        @rmdir($this->home);
    }

    public function testWithoutAPushKeyNothingIsRun(): void
    {
        self::assertSame(PushKeyState::NoPushKey, new PushKeyCheck($this->paths, $this->runner)->run());
        self::assertSame([], $this->runner->calls());
    }

    public function testItTestsThePushKeyAloneAndIgnoresUserConfigAndAgent(): void
    {
        touch($this->home.'/ssh/push_ed25519');
        $this->runner->expect(['ssh'], new CommandResult(0, 'authenticated via ssh key.'));

        $state = new PushKeyCheck($this->paths, $this->runner)->run('Bitbucket.org');

        self::assertSame(PushKeyState::Registered, $state);
        $argv = $this->runner->calls()[0]->argv;
        self::assertSame(['ssh', '-F', '/dev/null', '-i', $this->home.'/ssh/push_ed25519'], \array_slice($argv, 0, 5));
        self::assertContains('IdentitiesOnly=yes', $argv);
        self::assertContains('IdentityAgent=none', $argv);
        self::assertContains('StrictHostKeyChecking=yes', $argv);
        self::assertContains('UserKnownHostsFile='.$this->home.'/ssh/known_hosts', $argv);
        self::assertSame(['-T', 'git@bitbucket.org'], \array_slice($argv, -2));
        self::assertStringNotContainsString('deploy_ed25519', implode(' ', $argv));
    }

    public function testAnInteractiveCheckUsesAShorterBudget(): void
    {
        touch($this->home.'/ssh/push_ed25519');
        $this->runner->expect(['ssh'], new CommandResult(0, 'authenticated via ssh key.'));
        $this->runner->expect(['ssh'], new CommandResult(0, 'authenticated via ssh key.'));
        $check = new PushKeyCheck($this->paths, $this->runner);

        $check->run('bitbucket.org', true);
        $check->run('github.com');

        [$web, $deploy] = $this->runner->calls();
        self::assertSame(PushKeyCheck::INTERACTIVE_TIMEOUT, $web->timeout);
        self::assertContains('ConnectTimeout=5', $web->argv);
        self::assertGreaterThan(PushKeyCheck::INTERACTIVE_TIMEOUT, $deploy->timeout);
        self::assertContains('ConnectTimeout=10', $deploy->argv);
    }

    public function testAPortIsRefused(): void
    {
        touch($this->home.'/ssh/push_ed25519');
        $this->expectException(\InvalidArgumentException::class);
        new PushKeyCheck($this->paths, $this->runner)->run('[git.example.com]:2222');
    }

    #[DataProvider('outcomes')]
    public function testClassify(int $exit, string $output, PushKeyState $expected): void
    {
        self::assertSame($expected, PushKeyCheck::classify($exit, $output));
    }

    /**
     * @return iterable<string, array{int, string, PushKeyState}>
     */
    public static function outcomes(): iterable
    {
        yield 'bitbucket ok' => [0, 'logged in as someone.', PushKeyState::Registered];
        yield 'github ok (exit 1)' => [1, "Hi x! You've successfully authenticated, but GitHub does not provide shell access.", PushKeyState::Registered];
        yield 'not registered' => [255, 'git@bitbucket.org: Permission denied (publickey).', PushKeyState::NotRegistered];
        yield 'host not trusted' => [255, 'Host key verification failed.', PushKeyState::HostNotTrusted];
        yield 'unreachable' => [255, 'ssh: Could not resolve hostname x', PushKeyState::Unreachable];
    }

    public function testEveryStateHasAMessageNamingTheHost(): void
    {
        foreach (PushKeyState::cases() as $state) {
            self::assertNotSame('', $state->message('bitbucket.org'));
        }
        self::assertStringContainsString('push key is not registered on the git host bitbucket.org', PushKeyState::NotRegistered->message('bitbucket.org'));
    }

    #[DataProvider('remotes')]
    public function testHostFromRemote(string $remote, ?string $host): void
    {
        if (null === $host) {
            $this->expectException(\InvalidArgumentException::class);
        }
        self::assertSame($host, PushKeyCheck::hostFromRemote($remote));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function remotes(): iterable
    {
        yield 'scp-style' => ['git@Bitbucket.org:team/repo.git', 'bitbucket.org'];
        yield 'ssh url with port' => ['ssh://git@git.example.com:2222/team/repo.git', 'git.example.com'];
        yield 'https' => ['https://github.com/team/repo.git', 'github.com'];
        yield 'https with user' => ['https://user@github.com/team/repo.git', 'github.com'];
        yield 'option injection' => ['-oProxyCommand=x:repo', null];
        yield 'file path' => ['/srv/git/repo.git', null];
        yield 'file url' => ['file:///srv/git/repo.git', null];
        yield 'empty' => ['', null];
    }

    public function testTimeoutIsUnreachable(): void
    {
        touch($this->home.'/ssh/push_ed25519');
        $this->runner->expect(['ssh'], new CommandResult(0, ''), static function (): never {
            throw new \App\Fleet\Shell\CommandTimedOut('ssh timed out');
        });

        self::assertSame(PushKeyState::Unreachable, new PushKeyCheck($this->paths, $this->runner)->run());
    }

    public function testAnswersAreCachedPerHostAndKeyButUnreachableIsNot(): void
    {
        touch($this->home.'/ssh/push_ed25519');
        $cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $this->runner->expect(['ssh'], new CommandResult(255, 'git@bitbucket.org: Permission denied (publickey).'));
        $check = new PushKeyCheck($this->paths, $this->runner, $cache);

        $first = $check->run();
        $second = $check->run();
        self::assertSame($first, $second);
        self::assertSame(PushKeyState::NotRegistered, $first);
        self::assertCount(1, $this->runner->calls());
        $this->runner->expect(['ssh'], new CommandResult(255, 'Permission denied'));
        $check->run('github.com');
        self::assertCount(2, $this->runner->calls());
    }

    public function testItDoesNotTrustSystemKnownHosts(): void
    {
        self::assertContains('GlobalKnownHostsFile=/dev/null', PushKeyCheck::argv('/k', '/kh', 'bitbucket.org'));
    }
}
