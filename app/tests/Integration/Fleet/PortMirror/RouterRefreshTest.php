<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Fleet\PortMirror\RouterCopies;
use App\Fleet\PortMirror\RouterRefresh;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\PortMirrorTestCase;

/** FLE-326: the PROXY header goes to the router only after the router's live configuration trusts it. */
final class RouterRefreshTest extends PortMirrorTestCase
{
    private const string STATIC_PATH = '/mnt/ddev-global-cache/traefik/.static_config.yaml';
    private const string OLD_STATIC = "entryPoints:\n  http-8443:\n    address: ':8443'\n";

    private RouterRefresh $refresh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolation(\App\Domain\Host\IsolationMode::Enforce);
        $this->live->script(['http-8443']); // the Traefik API answers
        $this->catalogue(new NamedPort('typesense', 9108, 8108));
        $this->globalConfig("router_https_port: 8443\n");
        $copies = new RouterCopies($this->runner, $this->traefik, $this->live);
        $this->refresh = new RouterRefresh($copies, $this->converger, $this->mirror, $this->live, static function (float $s): void {
        });
    }

    /** DDEV 1.25.5's layout: no --configFile, /etc/traefik/traefik.yaml is a symlink to .static_config.yaml. */
    private function ddev125Router(string $staticCopy): void
    {
        $this->runner
            ->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"))
            ->expect(['docker', 'exec', 'ddev-router', 'cat', new RouterCopies($this->runner, $this->traefik, $this->live)->dynamicPath()], new CommandResult(0, 'old'))
            ->expect(['docker', 'exec', 'ddev-router', 'readlink', '-f', '/etc/traefik/traefik.yaml'], new CommandResult(0, self::STATIC_PATH."\n"))
            ->expect(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}'], new CommandResult(0, '["/usr/local/bin/docker-entrypoint.sh","/usr/local/bin/traefik"] null null'))
            ->expect(['docker', 'exec', 'ddev-router', 'cat', self::STATIC_PATH], new CommandResult(0, $staticCopy));
    }

    private function dynamic(): string
    {
        return (string) @file_get_contents($this->traefik->dynamicFile());
    }

    public function testThePlanSendsNoProxyHeaderWhileTheRouterDoesNotTrustIt(): void
    {
        $this->live->proxyTrusted = false;
        $this->mirror->sync();

        self::assertStringNotContainsString('proxyProtocol', $this->dynamic());
        // The trust itself (static) is written in enforce mode: it is harmless without a header and has to be live first.
        self::assertStringContainsString('proxyProtocol', (string) file_get_contents($this->traefik->staticFile()));

        $this->live->proxyTrusted = true;
        $this->mirror->sync();
        self::assertStringContainsString('proxyProtocol', $this->dynamic());

        $this->live->proxyTrusted = null;
        $this->mirror->sync();
        self::assertStringNotContainsString('proxyProtocol', $this->dynamic(), 'an unreachable API counts as not trusted');
    }

    public function testInOffModeNothingIsTrustedPushedOrRestarted(): void
    {
        $this->isolation(\App\Domain\Host\IsolationMode::Off);
        $this->live->proxyTrusted = false;
        $this->mirror->sync();
        // The router already runs the mirror's entry points; only the PROXY trust would be new, and it is not wanted here.
        $this->ddev125Router((string) file_get_contents($this->traefik->staticFile()));

        $result = $this->refresh->run();

        self::assertSame([], $this->runner->callsMatching(['docker', 'restart']));
        self::assertSame([], $result['problems']);
        self::assertFalse($result['restarted']);
        self::assertStringNotContainsString('proxyProtocol', (string) file_get_contents($this->traefik->staticFile()));
        self::assertStringNotContainsString('proxyProtocol', $this->dynamic());
    }

    public function testInReportModeADifferingStaticFileIsOnlyReportedNeverPushedOrRestarted(): void
    {
        $this->isolation(\App\Domain\Host\IsolationMode::Report);
        $this->live->proxyTrusted = false;
        $this->ddev125Router(self::OLD_STATIC);

        $result = $this->refresh->run();

        self::assertSame([], $this->runner->callsMatching(['docker', 'restart']));
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c']), 'the static file is not written');
        self::assertSame([], $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'cp']), 'no backup either, nothing to change');
        self::assertFalse($result['restarted']);
        self::assertNotSame([], array_filter($result['lines'], static fn (string $l): bool => str_contains($l, 'not applied')));
    }

    public function testAnUnhealthyRouterAfterTheRestartGetsItsOriginalStaticFileBack(): void
    {
        $this->live->proxyTrusted = false;
        $this->live->script(null); // the API never answers after the restart
        $this->ddev125Router(self::OLD_STATIC);
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));
        $this->runner->expect(['docker', 'restart', 'ddev-router'], new CommandResult(0, ''));
        // The restored original makes the router answer again.
        $this->runner->expect(['docker', 'restart', 'ddev-router'], new CommandResult(0, ''), function (): void {
            $this->live->script(['http-8443']);
        });

        $result = $this->refresh->run();

        // The original was saved first (host file and a copy inside the router) ...
        self::assertFileExists($this->traefik->dir().'/.fleet-router-static.backup');
        self::assertSame(self::OLD_STATIC, (string) file_get_contents($this->traefik->dir().'/.fleet-router-static.backup'));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'cp', '-f', self::STATIC_PATH]));
        // ... then written over, restarted, found unhealthy, restored (a second write of the static path) and restarted again.
        self::assertCount(2, $this->runner->callsMatching(['docker', 'restart', 'ddev-router']));
        self::assertCount(2, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c']));
        self::assertNotSame([], array_filter($result['problems'], static fn (string $p): bool => str_contains($p, 'not healthy') && str_contains($p, 'restored') && str_contains($p, 'NOT enforced on :443')), implode("\n", $result['problems']));
        self::assertStringNotContainsString('proxyProtocol', $this->dynamic());
    }

    /** FLE-336: no wait loop may run for minutes; an API that never answers costs at most the budget per wait, and progress is reported. */
    public function testWaitsAreBoundedAndProgressIsReported(): void
    {
        $slept = 0.0;
        $refresh = new RouterRefresh(new RouterCopies($this->runner, $this->traefik, $this->live), $this->converger, $this->mirror, $this->live, static function (float $s) use (&$slept): void {
            $slept += $s;
        });
        $this->live->proxyTrusted = false;
        $this->live->script(null); // the API never answers, so the verify fails and the rollback's verify fails too
        $this->ddev125Router(self::OLD_STATIC);
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));
        $progress = [];

        $result = $refresh->run(static function (string $line) use (&$progress): void {
            $progress[] = $line;
        });

        self::assertLessThanOrEqual(2 * RouterRefresh::WAIT_BUDGET, $slept, 'two waits (the restart, then the rollback) at most');
        self::assertSame($result['lines'], $progress, 'every line was reported as it happened');
        self::assertNotSame([], array_filter($progress, static fn (string $l): bool => str_contains($l, 'waiting up to')));
        foreach ($this->runner->callsMatching(['docker', 'restart', 'ddev-router']) as $call) {
            self::assertLessThanOrEqual(45.0, $call->timeout, 'a restart is bounded');
        }
        self::assertNotSame([], $result['problems']);
    }

    public function testTheProbeHasNothingToCheckWithoutARunningInstance(): void
    {
        $probe = new \App\Fleet\PortMirror\RouterProbe(new \App\Domain\Instance\InstanceRepository($this->em), new \App\Fleet\Instances\InstanceHostnames($this->hostSettings), $this->runner);

        self::assertNull($probe->check());
        self::assertSame([], $this->runner->callsMatching(['curl']));
    }

    public function testTheStaticFileIsFoundThroughTheSymlinkOfDdev125AndPushedThere(): void
    {
        $this->live->proxyTrusted = false;
        $this->ddev125Router(self::OLD_STATIC);
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));

        $this->refresh->run();

        $sh = $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'sh', '-c']);
        self::assertCount(1, $sh);
        self::assertContains(self::STATIC_PATH, $sh[0]->argv);
    }

    public function testWhenTheRouterStaticIsNotUpdatedTheMirrorStaysWithoutProxy(): void
    {
        // The router never trusts it (the push or the restart did not take): nothing may send the header.
        $this->live->proxyTrusted = false;
        $this->ddev125Router(self::OLD_STATIC);
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));

        $result = $this->refresh->run();

        self::assertStringNotContainsString('proxyProtocol', $this->dynamic());
        self::assertNotSame([], array_filter($result['problems'], static fn (string $p): bool => str_contains($p, 'stays without PROXY') && str_contains($p, 'NOT enforced on :443')), implode("\n", $result['problems']));
    }

    public function testAnUnreadableStaticFileKeepsTheMirrorWithoutProxyAndRestartsNothing(): void
    {
        $this->live->proxyTrusted = false;
        $this->runner
            ->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"))
            ->expect(['docker', 'exec', 'ddev-router', 'cat'], new CommandResult(1, ''));

        $result = $this->refresh->run();

        self::assertSame([], $this->runner->callsMatching(['docker', 'restart']));
        self::assertFalse($result['restarted']);
        self::assertStringNotContainsString('proxyProtocol', $this->dynamic());
        self::assertNotSame([], $result['problems']);
    }

    public function testTheHeaderIsEnabledOnlyAfterTheRestartMadeTheRouterTrustIt(): void
    {
        $this->live->proxyTrusted = false;
        $this->ddev125Router(self::OLD_STATIC);
        $this->runner->expect(['docker', 'restart', 'ddev-router'], new CommandResult(0, ''), function (): void {
            // After the restart the live API shows the trust.
            $this->live->proxyTrusted = true;
        });
        $this->runner->expect(['docker', 'inspect', '-f'], new CommandResult(0, "healthy\n"));
        // The second look at the router, after the mirror files were written with the header.
        $this->mirror->sync();
        $merged = \Symfony\Component\Yaml\Yaml::dump(array_replace_recursive(\Symfony\Component\Yaml\Yaml::parse(self::OLD_STATIC), \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($this->traefik->staticFile()))), 10, 2);
        $this->runner
            ->expect(['docker', 'inspect', '--format', '{{.State.Running}}'], new CommandResult(0, "true\n"))
            ->expect(['docker', 'exec', 'ddev-router', 'cat', new RouterCopies($this->runner, $this->traefik, $this->live)->dynamicPath()], new CommandResult(0, 'old'))
            ->expect(['docker', 'exec', 'ddev-router', 'readlink', '-f', '/etc/traefik/traefik.yaml'], new CommandResult(0, self::STATIC_PATH."\n"))
            ->expect(['docker', 'inspect', '--format', '{{json .Config.Entrypoint}} {{json .Config.Cmd}} {{json .Args}}'], new CommandResult(0, 'null null null'))
            ->expect(['docker', 'exec', 'ddev-router', 'cat', self::STATIC_PATH], new CommandResult(0, $merged));

        $result = $this->refresh->run();

        self::assertTrue($result['restarted']);
        self::assertSame([], $result['problems']);
        self::assertStringContainsString('proxyProtocol', $this->dynamic(), 'now the mirror sends PROXY');
        // The dynamic file was pushed twice: first without the header (before the restart), then with it.
        self::assertGreaterThanOrEqual(2, \count($this->runner->callsMatching(['docker', 'cp', $this->traefik->dynamicFile()])));
        $calls = array_map(static fn ($c): string => implode(' ', \array_slice($c->argv, 0, 3)), $this->runner->calls());
        $restart = array_search('docker restart ddev-router', $calls, true);
        $lastCopy = array_keys(array_filter($calls, static fn (string $c): bool => str_starts_with($c, 'docker cp')));
        self::assertNotFalse($restart);
        self::assertGreaterThan($restart, end($lastCopy), 'the last (PROXY) copy comes after the restart');
    }
}
