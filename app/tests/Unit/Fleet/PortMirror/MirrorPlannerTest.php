<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Fleet\PortMirror\MirrorPlanner;
use App\Fleet\PortMirror\TraefikDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MirrorPlannerTest extends TestCase
{
    private string $home;
    private TraefikDirectory $traefik;
    private MirrorPlanner $planner;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fleet-planner-'.bin2hex(random_bytes(6));
        mkdir($this->home.'/.ddev/traefik', 0o755, true);
        $this->traefik = new TraefikDirectory($this->home);
        $this->planner = new MirrorPlanner($this->traefik);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->home);
    }

    private function foreign(string $file, string $yaml): void
    {
        file_put_contents($this->traefik->dir().'/'.$file, $yaml);
    }

    private function globalConfig(string $yaml): void
    {
        file_put_contents($this->traefik->globalConfigFile(), $yaml);
    }

    /**
     * @param list<NamedPort> $catalogue
     *
     * @return array{list<string>, list<string>} the mirrored "public" ports and the skip reasons
     */
    private function plan(array $catalogue, bool $enabled = true): array
    {
        $plan = $this->planner->plan($enabled, $catalogue);

        return [array_map(static fn (int $port): string => (string) $port, $plan->publicPorts()), $plan->skipped];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function routerEntrypoints(): iterable
    {
        foreach ([80, 443, 8080, 8443, 8025, 8026, 10999] as $port) {
            yield "router port $port" => [$port];
        }
    }

    #[DataProvider('routerEntrypoints')]
    public function testAPublicPortTheRouterItselfUsesIsSkipped(int $public): void
    {
        [$mirrored, $skipped] = $this->plan([new NamedPort('web', $public, 18000)]);

        self::assertSame([], $mirrored);
        self::assertSame(['port "web" (public '.$public.') not mirrored: the port is used by ddev-router itself'], $skipped);
    }

    public function testAPublicPortThatIsAnotherEntrysRouterPortIsSkipped(): void
    {
        [$mirrored, $skipped] = $this->plan([new NamedPort('search', 8108, 18108), new NamedPort('typesense', 9108, 8108)]);

        self::assertSame(['9108'], $mirrored);
        self::assertSame(['port "search" (public 8108) not mirrored: the port is also a router (upstream) port of the catalogue'], $skipped);
    }

    public function testADuplicatePublicPortIsSkipped(): void
    {
        [$mirrored, $skipped] = $this->plan([new NamedPort('b', 9108, 8109), new NamedPort('a', 9108, 8108)]);

        self::assertSame(['9108'], $mirrored);
        self::assertSame(['port "b" (public 9108) not mirrored: duplicate of "a"'], $skipped);
    }

    public function testAPortAnotherStaticConfigDeclaresIsSkipped(): void
    {
        $this->foreign('static_config.other.yaml', "entryPoints:\n  other:\n    address: \"0.0.0.0:9108/tcp\"\n");

        [$mirrored, $skipped] = $this->plan([new NamedPort('typesense', 9108, 8108)]);

        self::assertSame([], $mirrored);
        self::assertSame(['port "typesense" (public 9108) not mirrored: static_config.other.yaml already declares an entrypoint on :9108 (delete that file to mirror this port)'], $skipped);
    }

    public function testAnEntrypointNameAnotherStaticConfigDeclaresIsSkipped(): void
    {
        $this->foreign('static_config.spike.yaml', "entryPoints:\n  fleet-mirror-9108:\n    address: \":7777\"\n");

        [$mirrored, $skipped] = $this->plan([new NamedPort('typesense', 9108, 8108)]);

        self::assertSame([], $mirrored);
        self::assertStringContainsString('static_config.spike.yaml already declares the entrypoint "fleet-mirror-9108"', $skipped[0]);
    }

    public function testAnUnparsableForeignFileIsIgnored(): void
    {
        $this->foreign('static_config.broken.yaml', "entryPoints: [unclosed\n  - : :\n");

        [$mirrored, $skipped] = $this->plan([new NamedPort('typesense', 9108, 8108)]);

        self::assertSame(['9108'], $mirrored);
        self::assertSame([], $skipped);
    }

    public function testThePortOfARouterHttpsPortThatIsNot443IsSkipped(): void
    {
        $this->globalConfig("router_https_port: \"9108\"\n");

        [$mirrored, $skipped] = $this->plan([new NamedPort('typesense', 9108, 8108)]);

        self::assertSame(['443'], $mirrored, 'only the site mirror, to :9108');
        self::assertSame(['port "typesense" (public 9108) not mirrored: the port is the router\'s https port (router_https_port in global_config.yaml)'], $skipped);
    }

    public function testTheSiteMirrorTargetsTheRouterHttpsPort(): void
    {
        $this->globalConfig("router_https_port: 8443\n");

        self::assertSame(8443, $this->planner->plan(true, [])->siteTarget);
    }

    /**
     * @return iterable<string, array{string|null, int|null}>
     */
    public static function httpsPortFiles(): iterable
    {
        yield 'absent file' => [null, 443];
        yield 'empty file' => ['', 443];
        yield 'key absent' => ["other: 1\n", 443];
        yield 'null' => ["router_https_port:\n", 443];
        yield 'int' => ["router_https_port: 8443\n", 8443];
        yield 'quoted' => ["router_https_port: '8443'\n", 8443];
        yield 'default' => ["router_https_port: 443\n", 443];
        yield 'not a number' => ["router_https_port: abc\n", null];
        yield 'zero' => ["router_https_port: 0\n", null];
        yield 'too high' => ["router_https_port: 70000\n", null];
        yield 'a bool' => ["router_https_port: true\n", null];
        yield 'a list' => ["router_https_port: [1]\n", null];
        yield 'not a mapping' => ["- a\n- b\n", null];
        yield 'invalid yaml' => ["a: [1\n", null];
    }

    #[DataProvider('httpsPortFiles')]
    public function testRouterHttpsPort(?string $yaml, ?int $expected): void
    {
        if (null !== $yaml) {
            $this->globalConfig($yaml);
        }

        self::assertSame($expected, $this->planner->routerHttpsPort());
    }

    public function testNoSiteMirrorWhenTheRouterAlreadyServes443(): void
    {
        $this->globalConfig("router_https_port: 443\n");

        $plan = $this->planner->plan(true, []);

        self::assertNull($plan->siteTarget);
        self::assertSame([], $plan->skipped);
    }

    public function testNoSiteMirrorWhenTheFileIsAbsent(): void
    {
        $plan = $this->planner->plan(true, []);

        self::assertNull($plan->siteTarget);
        self::assertSame([], $plan->skipped);
    }

    public function testNoSiteMirrorWhenThePortIsUnreadable(): void
    {
        $this->globalConfig("router_https_port: nope\n");

        $plan = $this->planner->plan(true, []);

        self::assertNull($plan->siteTarget);
        self::assertStringContainsString('site mirror (:443) not configured: cannot read router_https_port', $plan->skipped[0]);
    }

    public function testNoSiteMirrorWhenAForeignConfigDeclares443(): void
    {
        $this->globalConfig("router_https_port: 8443\n");
        $this->foreign('static_config.other.yaml', "entryPoints:\n  websecure:\n    address: \":443\"\n");

        $plan = $this->planner->plan(true, []);

        self::assertNull($plan->siteTarget);
        self::assertSame(['site mirror (:443) not mirrored: static_config.other.yaml already declares an entrypoint on :443 (delete that file to mirror the site on :443)'], $plan->skipped);
    }

    public function testNoSiteMirrorWhenAForeignConfigDeclaresTheName(): void
    {
        $this->globalConfig("router_https_port: 8443\n");
        $this->foreign('static_config.spike.yaml', "entryPoints:\n  fleet-mirror-443:\n    address: \":7443\"\n");

        $plan = $this->planner->plan(true, []);

        self::assertNull($plan->siteTarget);
        self::assertStringContainsString('already declares the entrypoint "fleet-mirror-443"', $plan->skipped[0]);
    }

    public function testDisabledMeansAnEmptyPlanWithoutReasons(): void
    {
        $this->globalConfig("router_https_port: 8443\n");

        $plan = $this->planner->plan(false, [new NamedPort('typesense', 9108, 8108)]);

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->skipped);
    }

    public function testOursIgnoresForeignDeclaredNames(): void
    {
        $this->foreign('static_config.spike.yaml', "entryPoints:\n  fleet-mirror-9999:\n    address: \":9999\"\n");

        self::assertSame(['fleet-mirror-443', 'fleet-mirror-9108'], $this->planner->ours(['web', 'fleet-mirror-9999', 'fleet-mirror-9108', 'fleet-mirror-443', 'fleet-mirror-9108']));
    }

    public function testTheHomeMustBeAbsolute(): void
    {
        $this->expectException(\LogicException::class);

        new TraefikDirectory('relative/home');
    }
}
