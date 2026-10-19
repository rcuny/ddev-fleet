<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ddev;

use App\Fleet\Ddev\Ddev;
use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandTimedOut;
use App\Fleet\Shell\RecordingCommandRunner;
use PHPUnit\Framework\TestCase;

final class DdevTest extends TestCase
{
    private RecordingCommandRunner $runner;
    private Ddev $ddev;
    private string $tmp;

    protected function setUp(): void
    {
        $this->runner = new RecordingCommandRunner();
        $this->ddev = new Ddev($this->runner, '/home/fleet');
        $this->tmp = sys_get_temp_dir().'/fleet-ddev-'.bin2hex(random_bytes(5));
        mkdir($this->tmp, 0o750, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp.'/.ddev/config.yaml');
        @rmdir($this->tmp.'/.ddev');
        @rmdir($this->tmp);
    }

    private function noop(): callable
    {
        return static fn () => null;
    }

    public function testStartAndStopArgvEnvAndCwd(): void
    {
        $this->ddev->start('/srv/fleet/instances/acme--dev', $this->noop());
        $this->ddev->stop('/srv/fleet/instances/acme--dev', $this->noop(), 90.0);

        [$start, $stop] = $this->runner->calls();
        self::assertSame(['ddev', 'start'], $start->argv);
        self::assertSame(['ddev', 'stop'], $stop->argv);
        self::assertSame('/srv/fleet/instances/acme--dev', $start->cwd);
        self::assertSame(['HOME' => '/home/fleet', 'DDEV_NONINTERACTIVE' => 'true', 'DDEV_NO_INSTRUMENTATION' => 'true'], $start->env);
        self::assertSame(Ddev::START_TIMEOUT, $start->timeout);
        self::assertSame(90.0, $stop->timeout);
    }

    public function testDeleteRunsInTheCheckoutWhenItHasAConfig(): void
    {
        mkdir($this->tmp.'/.ddev');
        touch($this->tmp.'/.ddev/config.yaml');

        $result = $this->ddev->delete($this->tmp, 'acme--dev', $this->noop());

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $this->runner->calls());
        self::assertSame(['ddev', 'delete', '--omit-snapshot', '--yes'], $this->runner->calls()[0]->argv);
        self::assertSame($this->tmp, $this->runner->calls()[0]->cwd);
    }

    public function testDeleteFallsBackToTheProjectNameWhenConfigIsMissing(): void
    {
        $this->ddev->delete($this->tmp, 'acme--dev', $this->noop());

        self::assertCount(1, $this->runner->calls());
        self::assertSame(['ddev', 'delete', '--omit-snapshot', '--yes', 'acme--dev'], $this->runner->calls()[0]->argv);
    }

    public function testDeleteFallsBackAfterAFailureInTheCheckout(): void
    {
        mkdir($this->tmp.'/.ddev');
        touch($this->tmp.'/.ddev/config.yaml');
        $this->runner->expect(['ddev', 'delete'], new CommandResult(1, '', 'boom'));

        $this->ddev->delete($this->tmp, 'acme--dev', $this->noop());

        self::assertCount(2, $this->runner->calls());
        self::assertSame('acme--dev', $this->runner->calls()[1]->argv[4]);
    }

    public function testDeleteNonZeroIsNotAnException(): void
    {
        $this->runner->expect(['ddev', 'delete'], new CommandResult(1, '', 'no such project'));

        $result = $this->ddev->delete($this->tmp, 'acme--dev', $this->noop());

        self::assertSame(1, $result->exitCode);
    }

    public function testDeleteRefusesAnythingButAnInstanceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ddev->delete($this->tmp, '--all', $this->noop());
    }

    public function testRunPassesTheLineVerbatim(): void
    {
        $this->ddev->run('/srv/fleet/instances/acme--dev', ['ddev', 'drush', 'cr'], ['SITE' => 'x'], $this->noop());

        $call = $this->runner->calls()[0];
        self::assertSame(['ddev', 'drush', 'cr'], $call->argv);
        self::assertSame(['SITE' => 'x', 'HOME' => '/home/fleet', 'DDEV_NONINTERACTIVE' => 'true', 'DDEV_NO_INSTRUMENTATION' => 'true'], $call->env);
    }

    public function testListParsesRawAndPlainShapes(): void
    {
        $raw = <<<'JSON'
            {"level":"info","msg":"checking","raw":"noise"}
            {"level":"info","msg":"","raw":[{"name":"acme--dev","status":"running","approot":"/srv/fleet/instances/acme--dev","httpurl":"x"},{"name":"acme--old","status":"stopped","approot":"/srv/fleet/instances/acme--old"}]}
            JSON;
        $this->runner->expect(['ddev', 'list'], new CommandResult(0, $raw));

        $projects = $this->ddev->list();

        self::assertSame(['acme--dev', 'acme--old'], array_keys($projects));
        self::assertTrue($projects['acme--dev']->isRunning());
        self::assertFalse($projects['acme--old']->isRunning());
        self::assertSame('/srv/fleet/instances/acme--dev', $projects['acme--dev']->approot);

        $this->runner->expect(['ddev', 'list'], new CommandResult(0, '[{"name":"acme--x","status":"paused","approot":"/a"}]'));
        self::assertSame('paused', $this->ddev->list()['acme--x']->status);

        $this->runner->expect(['ddev', 'list'], new CommandResult(0, '{"level":"info","msg":"No DDEV projects were found.","raw":[]}'));
        self::assertSame([], $this->ddev->list());

        self::assertSame(['ddev', 'list', '--json-output'], $this->runner->calls()[0]->argv);
        self::assertSame(Ddev::LIST_TIMEOUT, $this->runner->calls()[0]->timeout);
    }

    public function testListGarbageIsUnavailable(): void
    {
        foreach (['not json at all', '', '{"level":"info","msg":"no raw here"}', '[{"status":"running"}]'] as $stdout) {
            $this->runner->expect(['ddev', 'list'], new CommandResult(0, $stdout));
            try {
                $this->ddev->list();
                self::fail('expected DdevUnavailable for: '.$stdout);
            } catch (DdevUnavailable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->runner->expect(['ddev', 'list'], new CommandResult(1, '', 'broken'));
        $this->expectException(DdevUnavailable::class);
        $this->ddev->list();
    }

    public function testEveryDdevCallCarriesTheConfiguredHome(): void
    {
        $ddev = new Ddev($this->runner, '/home/other');
        $this->runner->expect(['ddev', 'list'], new CommandResult(0, '{"raw":[]}'));

        $ddev->start('/srv/fleet/instances/acme--dev', $this->noop());
        $ddev->list();

        foreach ($this->runner->calls() as $call) {
            self::assertSame('/home/other', $call->env['HOME']);
            self::assertSame('true', $call->env['DDEV_NONINTERACTIVE']);
        }
    }

    public function testHomeFallsBackToTheProcessHomeAndMustBeAbsolute(): void
    {
        $previous = getenv('HOME');
        putenv('HOME=/home/from-env');
        try {
            $fromEnv = new Ddev($this->runner);
            $fromEnv->start('/srv/fleet/instances/acme--dev', $this->noop());
            self::assertSame('/home/from-env', $this->runner->calls()[0]->env['HOME']);

            $this->expectException(\LogicException::class);
            new Ddev($this->runner, 'relative/home');
        } finally {
            false === $previous ? putenv('HOME') : putenv('HOME='.$previous);
        }
    }

    public function testListTimeoutThrowsDdevUnavailable(): void
    {
        $ddev = new Ddev(new class implements CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                throw new CommandTimedOut('too slow');
            }
        }, '/home/fleet');

        $this->expectException(DdevUnavailable::class);
        $ddev->list();
    }

    public function testRamUsageSumsServicesPerProject(): void
    {
        $stats = implode("\n", [
            '{"Name":"ddev-acme--dev-web","MemUsage":"512MiB / 62.5GiB"}',
            '{"Name":"ddev-acme--dev-db","MemUsage":"1.5GiB / 62.5GiB"}',
            '{"Name":"ddev-blog--main-web","MemUsage":"2048KiB / 62.5GiB"}',
            '{"Name":"ddev-router","MemUsage":"50MiB / 62.5GiB"}',
            '{"Name":"ddev-ssh-agent","MemUsage":"5MiB / 62.5GiB"}',
            '{"Name":"unrelated","MemUsage":"900MiB / 62.5GiB"}',
            'garbage',
        ]);
        $this->runner->expect(['docker', 'stats'], new CommandResult(0, $stats));

        $ram = $this->ddev->ramUsage();

        self::assertSame(['acme--dev' => 2048, 'blog--main' => 2], $ram);
        self::assertSame(['docker', 'stats', '--no-stream', '--format', '{{json .}}'], $this->runner->calls()[0]->argv);
        self::assertSame(Ddev::STATS_TIMEOUT, $this->runner->calls()[0]->timeout);
    }

    public function testRamUsageIsEmptyWhenDockerFails(): void
    {
        $this->runner->expect(['docker'], new CommandResult(1, '', 'cannot connect'));

        self::assertSame([], $this->ddev->ramUsage());
    }

    public function testPortConflictMarkers(): void
    {
        self::assertTrue(Ddev::isPortConflict('Error: Bind for 127.0.0.1:8080 failed: Port is already allocated'));
        self::assertTrue(Ddev::isPortConflict('driver failed ... failed to set up container networking: ...'));
        self::assertFalse(Ddev::isPortConflict('some other failure'));
        self::assertFalse(Ddev::isPortConflict(''));
    }
}
