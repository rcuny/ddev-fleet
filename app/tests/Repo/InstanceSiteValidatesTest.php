<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\GateMode;
use App\Fleet\System\GateWriter;
use App\Tests\Support\FixedOvhEndpoint;
use App\Tests\Support\SystemTemplates;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real Caddy over the main Caddyfile with instance snippets next to it. Skipped without FLEET_CADDY_BIN, like
 * RenderedCaddyfileValidatesTest (which also explains how to get a build with the OVH module).
 */
final class InstanceSiteValidatesTest extends TestCase
{
    private string $tree;
    private FleetPaths $paths;
    private string $caddy;

    protected function setUp(): void
    {
        $bin = getenv('FLEET_CADDY_BIN');
        if (false === $bin || '' === $bin || !is_executable($bin)) {
            self::markTestSkipped('FLEET_CADDY_BIN is not set to an executable Caddy.');
        }
        $this->caddy = $bin;
        $this->tree = sys_get_temp_dir().'/fleet-sites-'.bin2hex(random_bytes(6));
        mkdir($this->tree.'/etc/caddy/fleet/instances', 0o700, true);
        mkdir($this->tree.'/project/public', 0o700, true);
        mkdir($this->tree.'/home', 0o700, true);
        $this->paths = new FleetPaths($this->tree.'/srv', $this->tree.'/etc/caddy/fleet');

        $twig = SystemTemplates::twig();
        $host = new HostSettings('fleet.example.com');
        $host->changeTlsMode(TlsMode::Http01);
        file_put_contents($this->paths->caddyMainFile(), new CaddyfileRenderer($twig, $this->paths, new FixedOvhEndpoint(), $this->tree.'/project')->render($host));
        file_put_contents($this->paths->gateFile(), new GateWriter($twig, $this->paths, new CaddyReloader(new RecordingCommandRunner(), $this->paths))->render(GateMode::Authelia));
    }

    protected function tearDown(): void
    {
        if (!isset($this->tree) || !is_dir($this->tree)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tree, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tree);
    }

    private function validate(): CommandResult
    {
        return new ProcessCommandRunner()->run(
            [$this->caddy, 'validate', '--config', $this->paths->caddyMainFile(), '--adapter', 'caddyfile'],
            env: [
                'HOME' => $this->tree.'/home',
                'XDG_CONFIG_HOME' => $this->tree.'/home/config',
                'XDG_DATA_HOME' => $this->tree.'/home/data',
                'FLEET_PROXY_SECRET' => 'proxy-secret-0123456789abcdef-0123456789',
            ],
            timeout: 60.0,
        );
    }

    public function testAnEmptyInstancesDirectoryValidates(): void
    {
        $result = $this->validate();

        self::assertSame(0, $result->exitCode, $result->stderr);
    }

    public function testASnippetWithTwoAliasesValidates(): void
    {
        $snippet = SystemTemplates::twig()->render('system/instance-site.twig', [
            'id' => 'acme--main',
            'hosts' => ['acme--main.fleet.example.com', 'edge-acme--main.fleet.example.com', 'shop-acme--main.fleet.example.com'],
            'ports' => [],
            'stripped_headers' => CaddyfileRenderer::strippedHeaders(),
            'authelia' => CaddyfileRenderer::AUTHELIA,
            'groups_pattern' => '(?:^|,)(?:fleet\\-admins|fleet\\-project\\-acme)(?:,|$)',
            'router' => CaddyfileRenderer::DDEV_ROUTER,
            'router_host' => CaddyfileRenderer::DDEV_ROUTER_HOST,
        ]);
        file_put_contents($this->paths->instanceSiteFile('acme--main'), $snippet);

        $result = $this->validate();

        self::assertSame(0, $result->exitCode, $result->stderr);
    }

    public function testASnippetWithTwoNamedPortsValidates(): void
    {
        $hosts = ['acme--main.fleet.example.com', 'edge-acme--main.fleet.example.com'];
        $snippet = SystemTemplates::twig()->render('system/instance-site.twig', [
            'id' => 'acme--main',
            'hosts' => $hosts,
            'ports' => [
                ['name' => 'typesense', 'public' => 9108, 'router' => 8108, 'hosts' => array_map(static fn (string $h): string => $h.':9108', $hosts)],
                ['name' => 'mail', 'public' => 8025, 'router' => 18025, 'hosts' => array_map(static fn (string $h): string => $h.':8025', $hosts)],
            ],
            'stripped_headers' => CaddyfileRenderer::strippedHeaders(),
            'authelia' => CaddyfileRenderer::AUTHELIA,
            'groups_pattern' => '(?:^|,)(?:fleet\\-admins|fleet\\-project\\-acme)(?:,|$)',
            'router' => CaddyfileRenderer::DDEV_ROUTER,
            'router_host' => CaddyfileRenderer::DDEV_ROUTER_HOST,
        ]);
        file_put_contents($this->paths->instanceSiteFile('acme--main'), $snippet);

        $result = $this->validate();

        self::assertSame(0, $result->exitCode, $result->stderr);
    }
}
