<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Git;

use App\Fleet\FleetPaths;
use App\Fleet\Git\GitClient;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\RecordingCommandRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitClientTest extends TestCase
{
    private FleetPaths $paths;
    private RecordingCommandRunner $runner;
    private GitClient $git;

    protected function setUp(): void
    {
        $this->paths = new FleetPaths('/srv/fleet', '/etc/caddy/fleet');
        $this->runner = new RecordingCommandRunner();
        $this->git = new GitClient($this->runner, $this->paths);
    }

    public function testCloneArgvAndEnvAreExactlyTheHardenedOnes(): void
    {
        $dir = $this->paths->instanceDir('acme--develop');

        $this->git->clone('https://git.example.com/acme/blog.git', 'develop', $dir, static fn () => null);

        $call = $this->runner->calls()[0];
        self::assertSame(
            ['git', '-c', 'protocol.ext.allow=never', '-c', 'protocol.file.allow=never', 'clone', '--branch', 'develop', '--', 'https://git.example.com/acme/blog.git', '/srv/fleet/instances/acme--develop'],
            $call->argv,
        );
        self::assertSame('/srv/fleet/instances', $call->cwd);
        self::assertSame(1800.0, $call->timeout);
        self::assertSame(
            [
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_ALLOW_PROTOCOL' => 'https:ssh',
                'GIT_SSH_COMMAND' => "ssh -i '/srv/fleet/ssh/deploy_ed25519' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile='/srv/fleet/ssh/known_hosts'",
            ],
            $call->env,
        );
    }

    public function testCloneStreamsOutputAndReturnsTheResult(): void
    {
        $this->runner->expect(['git'], new CommandResult(128, '', "fatal: nope\n"));
        $seen = [];

        $result = $this->git->clone('https://git.example.com/x.git', 'main', '/srv/fleet/instances/a--b', static function (string $stream, string $chunk) use (&$seen): void {
            $seen[] = [$stream, $chunk];
        });

        self::assertSame(128, $result->exitCode);
        self::assertSame([['stderr', "fatal: nope\n"]], $seen);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function hostile(): iterable
    {
        yield 'branch option' => ['https://git.example.com/x.git', '--upload-pack=x', '/srv/fleet/instances/a--b'];
        yield 'branch single dash' => ['https://git.example.com/x.git', '-b', '/srv/fleet/instances/a--b'];
        yield 'url option' => ['--upload-pack=touch /tmp/x', 'main', '/srv/fleet/instances/a--b'];
        yield 'empty branch' => ['https://git.example.com/x.git', '', '/srv/fleet/instances/a--b'];
        yield 'whitespace in url' => ['https://git.example.com/x.git --foo', 'main', '/srv/fleet/instances/a--b'];
        yield 'newline in branch' => ['https://git.example.com/x.git', "main\nx", '/srv/fleet/instances/a--b'];
        yield 'dir outside instances' => ['https://git.example.com/x.git', 'main', '/tmp/a--b'];
        yield 'dir is instances itself' => ['https://git.example.com/x.git', 'main', '/srv/fleet/instances'];
    }

    #[DataProvider('hostile')]
    public function testBranchOrUrlStartingWithDashIsRefusedBeforeRunning(string $url, string $branch, string $dir): void
    {
        try {
            $this->git->clone($url, $branch, $dir, static fn () => null);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->runner->calls());
        }
    }

    public function testHeadIsTheShortSha(): void
    {
        $this->runner->expect(['git', 'rev-parse'], new CommandResult(0, "1a2b3c4\n"));

        self::assertSame('1a2b3c4', $this->git->head('/srv/fleet/instances/acme--develop'));
        $call = $this->runner->calls()[0];
        self::assertSame(['git', 'rev-parse', '--short', 'HEAD'], $call->argv);
        self::assertSame('/srv/fleet/instances/acme--develop', $call->cwd);
        self::assertSame(10.0, $call->timeout);
    }

    public function testHeadNeverThrows(): void
    {
        $this->runner->expect(['git'], new CommandResult(128, '', 'fatal: not a git repository'));
        self::assertSame('', $this->git->head('/srv/fleet/instances/a--b'));

        $throwing = new GitClient(new class implements \App\Fleet\Shell\CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                throw new CommandTimedOut('too slow');
            }
        }, $this->paths);
        self::assertSame('', $throwing->head('/srv/fleet/instances/a--b'));
    }
}
