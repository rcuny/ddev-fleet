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

    public function testALiveLinkAllowsTheSourcesWebAndTheSiteMirrorAddsTcpRouters(): void
    {
        $this->docker();
        @mkdir($this->traefik->dir(), 0o755, true);
        file_put_contents($this->traefik->staticFile(), "entryPoints:\n  fleet-mirror-443:\n    address: \":443\"\n");
        $link = new ResolvedLink('acme--staging', 'acme', 'acme--main', 'acme', 'typesense', 8108, null, 'ddev-acme--staging-web', 'ddev-acme--main-typesense');

        self::assertNull($this->access->sync(IsolationMode::Enforce, [$link]));

        $text = (string) file_get_contents($this->access->file());
        self::assertStringContainsString('ClientIP(`172.18.0.5/32`) || ClientIP(`172.18.0.6/32`) || ClientIP(`172.18.0.7/32`))', $text);
        self::assertStringContainsString('entryPoints: [fleet-mirror-443]', $text);
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
}
