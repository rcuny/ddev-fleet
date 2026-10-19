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
use App\Tests\Support\SystemTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real Caddy over the rendered files. Set FLEET_CADDY_BIN to a Caddy built with the OVH DNS module
 * (see tests/README-tests.md); without it the test is skipped, like the other real-binary checks.
 *
 *   mkdir -p var/tools && curl -fsSL "https://caddyserver.com/api/download?os=linux&arch=<arch>&p=github.com%2Fcaddy-dns%2Fovh" \
 *       -o var/tools/caddy && chmod +x var/tools/caddy && FLEET_CADDY_BIN=$PWD/var/tools/caddy vendor/bin/phpunit tests/Repo/RenderedCaddyfileValidatesTest.php
 */
final class RenderedCaddyfileValidatesTest extends TestCase
{
    private string $tree;
    private FleetPaths $paths;
    private string $caddy;

    protected function setUp(): void
    {
        $bin = getenv('FLEET_CADDY_BIN');
        if (false === $bin || '' === $bin || !is_executable($bin)) {
            self::markTestSkipped('FLEET_CADDY_BIN is not set to an executable Caddy (with the OVH DNS module).');
        }
        $this->caddy = $bin;
        $this->tree = sys_get_temp_dir().'/fleet-caddy-'.bin2hex(random_bytes(6));
        mkdir($this->tree.'/etc/caddy/fleet', 0o700, true);
        mkdir($this->tree.'/project/public', 0o700, true);
        mkdir($this->tree.'/home', 0o700, true);
        $this->paths = new FleetPaths($this->tree.'/srv', $this->tree.'/etc/caddy/fleet');
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

    /**
     * @return iterable<string, array{TlsMode, GateMode}>
     */
    public static function combinations(): iterable
    {
        foreach ([TlsMode::Http01, TlsMode::OvhDns] as $tls) {
            foreach ([GateMode::Setup, GateMode::Authelia] as $gate) {
                yield $tls->value.' + '.$gate->value => [$tls, $gate];
            }
        }
    }

    private function stage(TlsMode $tls, GateMode $gate, ?string $email = 'ops@example.com'): void
    {
        $host = new HostSettings('fleet.example.com');
        $host->changeTlsMode($tls);
        $host->changeAcmeEmail($email);
        $twig = SystemTemplates::twig();

        file_put_contents($this->paths->caddyMainFile(), new CaddyfileRenderer($twig, $this->paths, $this->tree.'/project')->render($host));
        $writer = new GateWriter($twig, $this->paths, new CaddyReloader(new RecordingCommandRunner(), $this->paths));
        file_put_contents($this->paths->gateFile(), $writer->render($gate));
    }

    private function caddy(string $subcommand): CommandResult
    {
        return new ProcessCommandRunner()->run(
            [$this->caddy, $subcommand, '--config', $this->paths->caddyMainFile(), '--adapter', 'caddyfile'],
            env: [
                'HOME' => $this->tree.'/home',
                'XDG_CONFIG_HOME' => $this->tree.'/home/config',
                'XDG_DATA_HOME' => $this->tree.'/home/data',
                // What caddy.service gets from /etc/caddy/fleet.env; validate must not need them to be real.
                'OVH_ENDPOINT' => 'ovh-eu',
                'OVH_APPLICATION_KEY' => 'application-key',
                'OVH_APPLICATION_SECRET' => 'application-secret',
                'OVH_CONSUMER_KEY' => 'consumer-key',
                'FLEET_PROXY_SECRET' => 'proxy-secret-0123456789abcdef-0123456789',
            ],
            timeout: 60.0,
        );
    }

    #[DataProvider('combinations')]
    public function testTheRenderedFilesPassCaddyValidate(TlsMode $tls, GateMode $gate): void
    {
        $this->stage($tls, $gate);

        $result = $this->caddy('validate');

        self::assertSame(0, $result->exitCode, $result->stderr);
    }

    public function testTheOvhModuleIsPresentInThisBuild(): void
    {
        $result = new ProcessCommandRunner()->run([$this->caddy, 'list-modules']);

        self::assertStringContainsString('dns.providers.ovh', $result->stdout, 'FLEET_CADDY_BIN must be built with github.com/caddy-dns/ovh');
    }

    public function testTheValidatorReallyRejectsABrokenGate(): void
    {
        $this->stage(TlsMode::Http01, GateMode::Setup);
        file_put_contents($this->paths->gateFile(), "not_a_directive {\n");

        self::assertNotSame(0, $this->caddy('validate')->exitCode);
    }

    #[DataProvider('combinations')]
    public function testTheIdentityHeadersAreStrippedBeforeAnyGateOrHandler(TlsMode $tls, GateMode $gate): void
    {
        $this->stage($tls, $gate);
        $adapted = $this->caddy('adapt');
        self::assertSame(0, $adapted->exitCode, $adapted->stderr);

        $config = json_decode($adapted->stdout, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        $site = self::siteRoute($config, 'fleet.example.com');
        $handlers = self::flatten($site);

        // Everything the app can see an identity from comes after all eight deletes, whichever gate is in front.
        $deletes = [];
        $firstOther = null;
        foreach ($handlers as $i => $handler) {
            $delete = $handler['request']['delete'] ?? null;
            if ('headers' === ($handler['handler'] ?? null) && \is_array($delete)) {
                $deletes[$i] = $delete;
            } elseif (null === $firstOther && \in_array($handler['handler'] ?? null, ['reverse_proxy', 'static_response', 'rewrite', 'file_server', 'request_body'], true)) {
                $firstOther = $i;
            }
        }
        self::assertSame(
            ['Remote-User', 'Remote_User', 'Remote-Groups', 'Remote_Groups', 'Remote-Name', 'Remote_Name', 'Remote-Email', 'Remote_Email'],
            array_merge(...array_values($deletes)),
        );
        self::assertNotNull($firstOther);
        $lastDelete = array_key_last($deletes);
        self::assertNotNull($lastDelete);
        self::assertLessThan($firstOther, $lastDelete, 'the gate or a handler runs before the strip finished');

        // The proxy secret reaches PHP only as a FastCGI parameter, never as a request header.
        self::assertStringContainsString('"FLEET_PROXY_AUTH":"{env.FLEET_PROXY_SECRET}"', (string) json_encode($handlers));
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function siteRoute(array $config, string $host): array
    {
        $servers = $config['apps']['http']['servers'] ?? [];
        self::assertIsArray($servers);
        foreach ($servers as $server) {
            foreach ($server['routes'] ?? [] as $route) {
                if (\in_array($host, $route['match'][0]['host'] ?? [], true)) {
                    return $route;
                }
            }
        }
        self::fail('No route for '.$host);
    }

    /**
     * The handlers in execution order, subroutes flattened.
     *
     * @param array<mixed> $route
     *
     * @return list<array<string, mixed>>
     */
    private static function flatten(array $route): array
    {
        $out = [];
        foreach ($route['handle'] ?? [] as $handler) {
            if ('subroute' === ($handler['handler'] ?? null)) {
                foreach ($handler['routes'] ?? [] as $inner) {
                    array_push($out, ...self::flatten($inner));
                }

                continue;
            }
            $out[] = $handler;
        }

        return $out;
    }
}
