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
        // The trust itself (static) is always written: it is harmless without a header and has to be live first.
        self::assertStringContainsString('proxyProtocol', (string) file_get_contents($this->traefik->staticFile()));

        $this->live->proxyTrusted = true;
        $this->mirror->sync();
        self::assertStringContainsString('proxyProtocol', $this->dynamic());

        $this->live->proxyTrusted = null;
        $this->mirror->sync();
        self::assertStringNotContainsString('proxyProtocol', $this->dynamic(), 'an unreachable API counts as not trusted');
    }

    public function testTheStaticFileIsFoundThroughTheSymlinkOfDdev125AndPushedThere(): void
    {
        $this->live->proxyTrusted = false;
        $this->ddev125Router(self::OLD_STATIC);

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
