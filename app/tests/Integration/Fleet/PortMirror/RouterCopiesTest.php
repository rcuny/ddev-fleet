<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Fleet\PortMirror\RouterCopies;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\PortMirrorTestCase;
use Symfony\Component\Yaml\Yaml;

/** FLE-326: the router's own copies of the mirror files against the host files. */
final class RouterCopiesTest extends PortMirrorTestCase
{
    private const string STATIC_PATH = '/mnt/ddev-global-cache/traefik/.static_config.yaml';

    private RouterCopies $copies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue(new NamedPort('typesense', 9108, 8108));
        $this->globalConfig("router_https_port: 8443\n");
        $this->mirror->sync();
        $this->copies = new RouterCopies($this->runner, $this->traefik, $this->live);
    }

    private function router(?string $dynamicCopy, ?string $staticCopy): void
    {
        $this->runner
            ->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"))
            ->expect(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}'], new CommandResult(0, '["traefik"] ["--configFile='.self::STATIC_PATH.'"] null'));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat', new RouterCopies($this->runner, $this->traefik, $this->live)->dynamicPath()], null === $dynamicCopy ? new CommandResult(1, '') : new CommandResult(0, $dynamicCopy));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat', self::STATIC_PATH], null === $staticCopy ? new CommandResult(1, '') : new CommandResult(0, $staticCopy));
    }

    private function hostDynamic(): string
    {
        return (string) file_get_contents($this->traefik->dynamicFile());
    }

    /** The router's merged static file as DDEV leaves it: the fragment's entry points without the PROXY trust. */
    private function oldStatic(): string
    {
        $fragment = Yaml::parse((string) file_get_contents($this->traefik->staticFile()));
        unset($fragment['entryPoints']['http-8443']);
        $fragment['entryPoints']['http-8443'] = ['address' => ':8443'];
        $fragment['log'] = ['level' => 'INFO'];

        return Yaml::dump($fragment, 10, 2);
    }

    private function currentStatic(): string
    {
        $fragment = Yaml::parse((string) file_get_contents($this->traefik->staticFile()));
        $fragment['entryPoints']['http-8443']['address'] = ':8443';
        $fragment['log'] = ['level' => 'INFO'];

        return Yaml::dump($fragment, 10, 2);
    }

    public function testAnUpgradedHostWithOldRouterCopiesPushesBothAndNeedsOneRestart(): void
    {
        $this->router('old dynamic', $this->oldStatic());
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertSame([], $repair['problems']);
        self::assertSame(['the port mirror dynamic file', 'the port mirror static configuration'], $repair['pushed']);
        self::assertTrue($repair['restartNeeded']);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'cp', $this->traefik->dynamicFile()]));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'mv', '-f']));
        // The static copy is the router's own file with the fragment merged in (the router's other keys stay).
        $static = $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c']);
        self::assertCount(1, $static);
        self::assertContains(self::STATIC_PATH, $static[0]->argv);
    }

    public function testNothingIsPushedOrRestartedWhenTheRouterAlreadyHoldsTheFilesAndTrustsTheProxy(): void
    {
        $this->router($this->hostDynamic(), $this->currentStatic());
        $this->live->proxyTrusted = true;

        $repair = $this->copies->repair();

        self::assertSame(['pushed' => [], 'restartNeeded' => false, 'problems' => []], $repair);
        self::assertSame([], $this->runner->callsMatching(['docker', 'cp']));
    }

    public function testACopyPushedEarlierWithoutARestartStillAsksForOne(): void
    {
        $this->router($this->hostDynamic(), $this->currentStatic());
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertSame([], $repair['pushed']);
        self::assertTrue($repair['restartNeeded'], 'the live entry point does not trust the PROXY header yet');
    }

    public function testAStoppedRouterIsLeftAlone(): void
    {
        $this->runner->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(1, ''));

        self::assertSame(['pushed' => [], 'restartNeeded' => false, 'problems' => []], $this->copies->repair());
        self::assertSame([], $this->copies->check());
    }

    public function testAnUnknownStaticFileIsReportedAndNoRestartIsAsked(): void
    {
        $this->runner->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"));
        $this->runner->expect(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}'], new CommandResult(0, '["traefik"] [] null'));
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat'], new CommandResult(0, $this->hostDynamic()));
        $this->live->proxyTrusted = false;

        $repair = $this->copies->repair();

        self::assertFalse($repair['restartNeeded']);
        self::assertNotSame([], $repair['problems']);
    }

    public function testCheckReportsEveryMismatch(): void
    {
        $this->router('old dynamic', $this->oldStatic());
        $this->live->proxyTrusted = false;

        $rows = $this->copies->check();

        self::assertCount(3, $rows);
        self::assertSame(['different', 'different', 'does not trust (restart pending)'], array_column($rows, 'actual'));
        self::assertSame([false, false, false], array_column($rows, 'ok'));
    }

    public function testCheckPassesWhenEverythingMatches(): void
    {
        $this->router($this->hostDynamic(), $this->currentStatic());
        $this->live->proxyTrusted = true;

        self::assertSame([true, true, true], array_column($this->copies->check(), 'ok'));
    }

    public function testAMirrorThatIsGoneRemovesTheRoutersDynamicCopy(): void
    {
        $this->switchMirror(false);
        $this->mirror->sync();
        self::assertFileDoesNotExist($this->traefik->dynamicFile());
        $this->router('left over', null);

        $repair = $this->copies->repair();

        self::assertSame(['removed the port mirror dynamic file'], $repair['pushed']);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'rm', '-f']));
    }
}
