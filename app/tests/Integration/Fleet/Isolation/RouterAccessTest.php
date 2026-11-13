<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Isolation;

use App\Domain\Host\IsolationMode;
use App\Domain\Instance\InstanceState;
use App\Fleet\Isolation\DockerNetworks;
use App\Fleet\Isolation\ResolvedLink;
use App\Fleet\Isolation\RouterAccess;
use App\Fleet\PortMirror\TraefikDirectory;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;

/** FLE-326: the router-path allow-list file follows the instances, their addresses and the live links. */
final class RouterAccessTest extends InstanceJobTestCase
{
    private const string GATEWAY_FORMAT = '{{range .IPAM.Config}}{{.Gateway}} {{end}}';
    private const string ADDRESSES_FORMAT = '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}';

    private TraefikDirectory $traefik;
    private RouterAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance('main', state: InstanceState::Running);
        $this->instance('staging', state: InstanceState::Running);
        $this->traefik = new TraefikDirectory($this->paths->home());
        $this->access = new RouterAccess($this->instances, $this->instanceHostnames, new DockerNetworks($this->runner), $this->ddev, $this->traefik);
    }

    private function docker(): void
    {
        $this->runner
            ->expect(['docker', 'network', 'inspect', '--format', self::GATEWAY_FORMAT], new CommandResult(0, "172.18.0.1 \n"))
            ->expect(['docker', 'network', 'inspect', '--format', self::ADDRESSES_FORMAT, 'ddev_default'], new CommandResult(0, "ddev-acme--main-web|172.18.0.5/16\nddev-acme--main-db|172.18.0.6/16\nddev-acme--staging-web|172.18.0.7/16\nddev-router|172.18.0.2/16\n"))
            ->expect(['docker', 'ps', '-a', '--filter', 'label=com.ddev.site-name=acme--main'], new CommandResult(0, "ddev-acme--main-web|web|\nddev-acme--main-db|db|\n"))
            ->expect(['docker', 'ps', '-a', '--filter', 'label=com.ddev.site-name=acme--staging'], new CommandResult(0, "ddev-acme--staging-web|web|\n"));
    }

    public function testEnforceWritesOneDenyRouterPerInstanceAllowingGatewayLoopbackAndItsOwnContainers(): void
    {
        $this->docker();

        self::assertNull($this->access->sync(IsolationMode::Enforce, []));

        $text = (string) file_get_contents($this->access->file());
        self::assertStringContainsString('(Host(`acme--main.', $text);
        self::assertStringContainsString('!(ClientIP(`127.0.0.1/32`) || ClientIP(`172.18.0.1/32`) || ClientIP(`172.18.0.5/32`) || ClientIP(`172.18.0.6/32`))', $text);
        self::assertStringContainsString('!(ClientIP(`127.0.0.1/32`) || ClientIP(`172.18.0.1/32`) || ClientIP(`172.18.0.7/32`))', $text);
        self::assertStringNotContainsString('172.18.0.2/32', $text, 'the router itself is not an allowed source');
        self::assertStringNotContainsString('tcp:', $text, 'no site mirror declared');
    }

    public function testASecondSyncWithTheSameStateRewritesNothing(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        $mtime = filemtime($this->access->file());
        clearstatcache();
        $this->docker();
        touch($this->access->file(), $mtime - 100);

        $this->access->sync(IsolationMode::Enforce, []);

        self::assertSame($mtime - 100, filemtime($this->access->file()));
    }

    public function testALiveLinkAllowsTheSourcesWebAndNeverRendersTcpRouters(): void
    {
        $this->docker();
        @mkdir($this->traefik->dir(), 0o755, true);
        file_put_contents($this->traefik->staticFile(), "entryPoints:\n  fleet-mirror-443:\n    address: \":443\"\n");
        $link = new ResolvedLink('acme--staging', 'acme', 'acme--main', 'acme', 'typesense', 8108, null, 'ddev-acme--staging-web', 'ddev-acme--main-typesense');

        self::assertNull($this->access->sync(IsolationMode::Enforce, [$link]));

        $text = (string) file_get_contents($this->access->file());
        self::assertStringContainsString('ClientIP(`172.18.0.5/32`) || ClientIP(`172.18.0.6/32`) || ClientIP(`172.18.0.7/32`))', $text);
        // The :443 mirror is untouched by the isolation: no TCP router, even when the mirror exists.
        self::assertStringNotContainsString('tcp:', $text);
        self::assertStringNotContainsString('fleet-mirror-443', $text);
    }

    public function testTheHttpDenyRoutersSkipTheMirrorEntryPoints(): void
    {
        $this->docker();
        $live = new \App\Tests\Support\FakeLiveEntrypoints(['http-8080', 'http-8443', 'fleet-mirror-443', 'fleet-mirror-9108']);
        $access = new RouterAccess($this->instances, $this->instanceHostnames, new DockerNetworks($this->runner), $this->ddev, $this->traefik, $live);

        self::assertNull($access->sync(IsolationMode::Enforce, []));

        $text = (string) file_get_contents($access->file());
        self::assertStringContainsString("      entryPoints: [http-8080, http-8443]\n", $text);
        self::assertStringNotContainsString('fleet-mirror', $text);
    }

    public function testWhenTheTraefikApiIsDownTheLastGoodEntryPointsAreKeptAndTheInstancesStillRefresh(): void
    {
        $this->docker();
        $live = new \App\Tests\Support\FakeLiveEntrypoints(['http-8080', 'http-8443', 'fleet-mirror-443']);
        $access = new RouterAccess($this->instances, $this->instanceHostnames, new DockerNetworks($this->runner), $this->ddev, $this->traefik, $live);
        self::assertNull($access->sync(IsolationMode::Enforce, []));
        $good = (string) file_get_contents($access->file());

        // The API stops answering: same file, no rewrite without entryPoints, a warning.
        $this->runner->reset();
        $this->docker();
        $down = new RouterAccess($this->instances, $this->instanceHostnames, new DockerNetworks($this->runner), $this->ddev, $this->traefik, new \App\Tests\Support\FakeLiveEntrypoints(null));
        $problem = $down->sync(IsolationMode::Enforce, []);

        self::assertNotNull($problem);
        self::assertStringContainsString('last good file', $problem);
        self::assertSame($good, (string) file_get_contents($access->file()));
        self::assertStringContainsString('entryPoints: [http-8080, http-8443]', (string) file_get_contents($access->file()));

        // A new instance while the API is down still gets its routers, on the same entry points.
        $this->instance('extra', state: InstanceState::Running);
        $this->runner->reset();
        $this->docker();
        $this->runner->expect(['docker', 'ps', '-a', '--filter', 'label=com.ddev.site-name=acme--extra'], new CommandResult(0, "ddev-acme--extra-web|web|\n"));
        $down->sync(IsolationMode::Enforce, []);
        $text = (string) file_get_contents($access->file());
        self::assertStringContainsString('Host(`acme--extra.', $text);
        self::assertSame(2 * 3, substr_count($text, 'entryPoints: [http-8080, http-8443]'));
    }

    public function testWithNoLastFileAndTheApiDownTheDenyRoutersAreBoundToEveryEntryPoint(): void
    {
        $this->docker();
        $down = new RouterAccess($this->instances, $this->instanceHostnames, new DockerNetworks($this->runner), $this->ddev, $this->traefik, new \App\Tests\Support\FakeLiveEntrypoints(null));

        self::assertNotNull($down->sync(IsolationMode::Enforce, []));

        self::assertFileExists($down->file());
        self::assertStringNotContainsString('entryPoints: [http', (string) file_get_contents($down->file()));
    }

    public function testReportAndOffRemoveTheFile(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        self::assertFileExists($this->access->file());

        self::assertNull($this->access->sync(IsolationMode::Report, []));
        self::assertFileDoesNotExist($this->access->file());
    }

    public function testWhenDockerIsUnreadableTheFileIsLeftAsItWas(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        $before = (string) file_get_contents($this->access->file());
        $this->runner->reset();
        $this->runner->expect(['docker', 'network', 'inspect', '--format', self::GATEWAY_FORMAT], new CommandResult(1, ''));

        $problem = $this->access->sync(IsolationMode::Enforce, []);

        self::assertNotNull($problem);
        self::assertSame($before, (string) file_get_contents($this->access->file()));
    }

    private const array RUNNING = ['docker', 'inspect', '--format', '{{.State.Running}}', 'ddev-router'];

    private function router(string $copy = ''): void
    {
        $this->runner->expect(self::RUNNING, new CommandResult(0, "true\n"));
        if ('' !== $copy) {
            $this->runner->expect(['docker', 'exec', 'ddev-router', 'cat', RouterAccess::ROUTER_FILE], new CommandResult(0, $copy));
        }
    }

    public function testAnEnforcePassCopiesTheFileIntoTheRouterWhenItsCopyIsMissingOrStale(): void
    {
        $this->docker();
        $this->router('stale');

        self::assertNull($this->access->sync(IsolationMode::Enforce, []));

        $cp = $this->runner->callsMatching(['docker', 'cp', $this->access->file(), 'ddev-router:'.RouterAccess::ROUTER_FILE.'.tmp']);
        self::assertCount(1, $cp);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'mv', '-f', RouterAccess::ROUTER_FILE.'.tmp', RouterAccess::ROUTER_FILE]));
        self::assertFileExists($this->traefik->routerAccessMarker());
    }

    public function testNothingIsCopiedWhenTheRouterAlreadyHoldsTheSameFile(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        $this->runner->reset();
        $this->docker();
        $this->router((string) file_get_contents($this->access->file()));

        $this->access->sync(IsolationMode::Enforce, []);

        self::assertSame([], $this->runner->callsMatching(['docker', 'cp']));
    }

    public function testOffRemovesTheRouterCopyAtOnceAndForgetsTheMarker(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        $this->runner->reset();
        $this->router();
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'rm', '-f', RouterAccess::ROUTER_FILE, RouterAccess::ROUTER_FILE.'.tmp'], new CommandResult(0, ''));

        self::assertNull($this->access->sync(IsolationMode::Off, []));

        self::assertFileDoesNotExist($this->access->file());
        self::assertCount(1, $this->runner->callsMatching(['docker', 'exec', 'ddev-router', 'rm', '-f']));
        self::assertFileDoesNotExist($this->traefik->routerAccessMarker());
    }

    public function testAFailedRemovalFromTheRouterIsRetriedByTheNextPass(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        $this->runner->reset();
        $this->router();
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'rm', '-f'], new CommandResult(1, 'no'));

        self::assertNotNull($this->access->sync(IsolationMode::Report, []));
        self::assertFileExists($this->traefik->routerAccessMarker());

        // The host file is gone but the marker keeps the retry alive.
        $this->runner->reset();
        $this->router();
        $this->runner->expect(['docker', 'exec', 'ddev-router', 'rm', '-f'], new CommandResult(0, ''));
        self::assertNull($this->access->sync(IsolationMode::Report, []));
        self::assertFileDoesNotExist($this->traefik->routerAccessMarker());
    }

    public function testOffWithNothingEverWrittenRunsNoDockerCommand(): void
    {
        $this->runner->reset();

        self::assertNull($this->access->sync(IsolationMode::Off, []));

        self::assertSame([], $this->runner->calls());
    }

    public function testVerifyComparesTheRoutersCopyWithTheHostFile(): void
    {
        $this->docker();
        $this->access->sync(IsolationMode::Enforce, []);
        $host = (string) file_get_contents($this->access->file());

        $this->runner->reset();
        $this->router($host);
        self::assertSame(['expected' => 'same as the host file', 'actual' => 'same', 'ok' => true], $this->access->verify(IsolationMode::Enforce));

        $this->runner->reset();
        $this->router('old');
        self::assertFalse($this->access->verify(IsolationMode::Enforce)['ok'] ?? true);

        $this->runner->reset();
        $this->router('old');
        self::assertFalse($this->access->verify(IsolationMode::Off)['ok'] ?? true, 'a copy in the router in off mode is a failure');
    }
}
