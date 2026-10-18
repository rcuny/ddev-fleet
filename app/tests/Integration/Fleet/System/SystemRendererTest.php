<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\System;

use App\Domain\Host\HostSettings;
use App\Domain\Host\TlsMode;
use App\Domain\Secret\SecretRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\AutheliaConfigRenderer;
use App\Fleet\System\CaddyEnvRenderer;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\GateMode;
use App\Fleet\System\GateWriter;
use App\Fleet\System\SystemRenderer;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RecordingApplier;
use App\Tests\Support\SystemTemplates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class SystemRendererTest extends KernelTestCase
{
    use FleetDatabase;

    private const string PROXY_SECRET = 'proxy-secret-0123456789abcdef-0123456789';

    private FleetPaths $paths;
    private SecretStore $store;
    private RecordingCommandRunner $runner;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->store = new SecretStore(new SecretRepository($em), new SecretBox(new SecretKeyFile($paths)), new MockClock('2026-10-19 10:00:00 UTC'));
        $this->runner = new RecordingCommandRunner();
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function renderer(Applier $applier): SystemRenderer
    {
        $twig = SystemTemplates::twig();

        return new SystemRenderer(
            new CaddyfileRenderer($twig, $this->paths, '/opt/ddev-fleet/app'),
            new CaddyEnvRenderer($this->store, self::PROXY_SECRET),
            new AutheliaConfigRenderer($twig, $this->paths),
            new GateWriter($twig, $this->paths, new CaddyReloader($this->runner, $this->paths)),
            $this->paths,
            $applier,
        );
    }

    private static function host(TlsMode $mode = TlsMode::Http01): HostSettings
    {
        $host = new HostSettings('fleet.example.com');
        $host->changeTlsMode($mode);

        return $host;
    }

    private function storeOvh(): void
    {
        $this->store->putFleetWide('ovh_endpoint', 'ovh-eu', 'admin');
        $this->store->putFleetWide('ovh_application_key', 'app-key-12345678', 'admin');
        $this->store->putFleetWide('ovh_application_secret', 'app-secret-12345678', 'admin');
        $this->store->putFleetWide('ovh_consumer_key', 'consumer-key-12345678', 'admin');
    }

    /**
     * @return list<string> the staged files, relative to the staging directory
     */
    private function staged(): array
    {
        $files = [];
        $dir = $this->paths->stagingDir();
        foreach ((array) scandir($dir) as $target) {
            if ('.' === $target || '..' === $target || !is_dir($dir.'/'.$target)) {
                continue;
            }
            foreach ((array) scandir($dir.'/'.$target) as $file) {
                if ('.' !== $file && '..' !== $file) {
                    $files[] = $target.'/'.$file;
                }
            }
        }
        sort($files);

        return $files;
    }

    public function testStagingFilesAndModes(): void
    {
        $this->renderer(new RecordingApplier())->renderStaging(self::host());

        self::assertSame(['authelia/configuration.yml', 'caddy/Caddyfile', 'caddy/env'], $this->staged());
        clearstatcache();
        self::assertSame(0o600, fileperms($this->paths->stagingFile('caddy', 'env')) & 0o777);
        self::assertSame(0o640, fileperms($this->paths->stagingFile('caddy', 'Caddyfile')) & 0o777);
        self::assertSame(0o640, fileperms($this->paths->stagingFile('authelia', 'configuration.yml')) & 0o777);
        self::assertSame(0o700, fileperms(\dirname($this->paths->stagingFile('caddy', 'env'))) & 0o777);
        self::assertStringContainsString('fleet.example.com {', (string) file_get_contents($this->paths->stagingFile('caddy', 'Caddyfile')));
        self::assertSame('FLEET_PROXY_SECRET='.self::PROXY_SECRET."\n", file_get_contents($this->paths->stagingFile('caddy', 'env')));
        self::assertStringContainsString("domain: 'fleet.example.com'", (string) file_get_contents($this->paths->stagingFile('authelia', 'configuration.yml')));
    }

    public function testAFailingRenderStagesNothing(): void
    {
        // DNS mode without OVH secrets: the Caddyfile would render, but the env cannot, so no file may appear.
        try {
            $this->renderer(new RecordingApplier())->renderStaging(self::host(TlsMode::OvhDns));
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->staged());
        }
    }

    public function testStageAndApplyRemovesTheStagedFilesAfterSuccess(): void
    {
        $this->storeOvh();
        $applier = new RecordingApplier();
        $renderer = $this->renderer($applier);

        $renderer->stageAndApply(ApplyTarget::Caddy, self::host(TlsMode::OvhDns));

        self::assertSame([ApplyTarget::Caddy], $applier->applied);
        self::assertSame([], $this->staged(), 'the OVH credentials do not outlive the apply');
    }

    public function testStageAndApplyRemovesTheStagedFilesAfterFailure(): void
    {
        $this->storeOvh();
        $applier = new RecordingApplier(new \RuntimeException('helper refused'));
        $renderer = $this->renderer($applier);

        try {
            $renderer->stageAndApply(ApplyTarget::Caddy, self::host(TlsMode::OvhDns));
            self::fail('Expected the failure to surface.');
        } catch (\RuntimeException $e) {
            self::assertSame('helper refused', $e->getMessage());
        }

        self::assertSame([], $this->staged());
        self::assertStringNotContainsString('app-secret', (string) json_encode($this->staged()));
    }

    public function testStageAndApplyAlsoCleansUpAfterAnInterruptedWrite(): void
    {
        $renderer = $this->renderer(new RecordingApplier());
        $renderer->renderStaging(self::host(), ApplyTarget::Caddy);
        file_put_contents(\dirname($this->paths->stagingFile('caddy', 'env')).'/.env.deadbeef.tmp', 'OVH_APPLICATION_SECRET=x');

        $renderer->stageAndApply(ApplyTarget::Caddy, self::host());

        self::assertSame([], $this->staged());
    }

    public function testApplyingOneTargetNeverStagesOrTouchesTheOther(): void
    {
        $this->storeOvh();
        $seen = [];
        $applier = new class($seen, $this->paths) implements Applier {
            /** @param list<string> $seen */
            public function __construct(public array &$seen, private readonly FleetPaths $paths)
            {
            }

            public function apply(ApplyTarget $target): void
            {
                $this->seen[] = $target->value.': '.implode(',', array_values(array_diff((array) scandir($this->paths->stagingDir().'/'.$target->value), ['.', '..'])));
            }
        };
        $renderer = $this->renderer($applier);

        $renderer->stageAndApply(ApplyTarget::Authelia, self::host(TlsMode::OvhDns));
        $renderer->stageAndApply(ApplyTarget::Caddy, self::host(TlsMode::OvhDns));

        self::assertSame(['authelia: configuration.yml', 'caddy: Caddyfile,env'], $seen);
        self::assertFileDoesNotExist($this->paths->stagingFile('caddy', 'env'));
    }

    public function testRenderGatePicksSetupWhileSetupIsIncomplete(): void
    {
        $renderer = $this->renderer(new RecordingApplier());
        $host = self::host();

        self::assertSame(GateMode::Setup, $renderer->renderGate($host));
        self::assertStringContainsString('redir @fleet_closed /setup 302', (string) file_get_contents($this->paths->gateFile()));

        $host->markSetupComplete();
        self::assertSame(GateMode::Authelia, $renderer->renderGate($host));
        self::assertStringContainsString('forward_auth', (string) file_get_contents($this->paths->gateFile()));

        self::assertSame(GateMode::Setup, $renderer->renderGate($host, GateMode::Setup));
    }
}
