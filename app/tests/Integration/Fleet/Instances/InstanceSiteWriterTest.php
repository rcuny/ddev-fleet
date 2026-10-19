<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceSiteWriter;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\SystemTemplates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class InstanceSiteWriterTest extends KernelTestCase
{
    use FleetDatabase;

    private FleetPaths $paths;
    private RecordingCommandRunner $runner;
    private InstanceSiteWriter $writer;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $host = new HostSettingsRepository($em);
        $host->getOrCreate('fleet.example.com');
        $this->runner = new RecordingCommandRunner();
        $this->writer = new InstanceSiteWriter(
            SystemTemplates::twig(),
            $this->paths,
            new CaddyReloader($this->runner, $this->paths),
            new InstanceHostnames($host),
            new LockFactory(new FlockStore($this->paths->locksDir())),
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function project(string ...$hostnames): Project
    {
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $project->changeHostnames(array_values($hostnames));

        return $project;
    }

    private function instance(Project $project, string $label = 'main'): Instance
    {
        return new Instance($project, $label, 'full', 'develop', 'admin');
    }

    private function site(string $id = 'acme--main'): string
    {
        return (string) file_get_contents($this->paths->instanceSiteFile($id));
    }

    public function testOneBlockWithAliasesAndTheAuthGate(): void
    {
        $project = $this->project('edge', 'shop');
        $this->writer->write($this->instance($project), $project);
        $site = $this->site();

        self::assertStringContainsString("\nacme--main.fleet.example.com, edge-acme--main.fleet.example.com, shop-acme--main.fleet.example.com {\n", $site);
        self::assertSame(1, preg_match_all('/^\S.* \{$/m', $site), 'one site block');
        self::assertStringContainsString('forward_auth '.CaddyfileRenderer::AUTHELIA.' {', $site);
        self::assertStringContainsString('reverse_proxy '.CaddyfileRenderer::DDEV_ROUTER, $site);
        self::assertLessThan(strpos($site, 'reverse_proxy'), strpos($site, 'forward_auth'), 'the gate comes before the proxy');
        self::assertStringContainsString('Strict-Transport-Security', $site);
        self::assertStringContainsString('-Server', $site);
        self::assertSame(0o640, fileperms($this->paths->instanceSiteFile('acme--main')) & 0o777);
        self::assertCount(1, $this->runner->callsMatching(['caddy', 'reload']));
    }

    public function testNoCopyHeadersAndAllRemoteHeadersStripped(): void
    {
        $project = $this->project();
        $this->writer->write($this->instance($project), $project);
        $site = $this->site();

        self::assertStringNotContainsString('copy_headers', $site);
        foreach (CaddyfileRenderer::strippedHeaders() as $header) {
            self::assertStringContainsString('request_header -'.$header."\n", $site);
        }
        self::assertLessThan(strpos($site, 'forward_auth'), strpos($site, 'request_header'), 'the strip runs before the gate');
    }

    public function testReloadFailureRestoresThePreviousFile(): void
    {
        $project = $this->project();
        $this->writer->write($this->instance($project), $project);
        $before = $this->site();

        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: bad config'));
        $withAlias = $this->project('edge');
        try {
            $this->writer->write($this->instance($withAlias), $withAlias);
            self::fail('expected the failure to be rethrown');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('bad config', $e->getMessage());
        }

        self::assertSame($before, $this->site());
    }

    public function testReloadFailureWithNoPreviousLeavesNothing(): void
    {
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: bad config'));
        $project = $this->project();

        try {
            $this->writer->write($this->instance($project), $project);
            self::fail('expected the failure to be rethrown');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        self::assertFileDoesNotExist($this->paths->instanceSiteFile('acme--main'));
        self::assertSame([], glob($this->paths->caddyInstancesDir().'/*') ?: []);
    }

    public function testRemoveDeletesAndReloads(): void
    {
        $project = $this->project();
        $this->writer->write($this->instance($project), $project);
        $this->runner->reset();

        $this->writer->remove('acme--main');

        self::assertFileDoesNotExist($this->paths->instanceSiteFile('acme--main'));
        self::assertCount(1, $this->runner->callsMatching(['caddy', 'reload']));

        $this->runner->reset();
        $this->writer->remove('acme--main');
        self::assertSame([], $this->runner->calls(), 'a missing site is fine and needs no reload');
    }

    public function testBadIdAndNewlineInHostnameAreRefused(): void
    {
        try {
            $this->writer->remove('../etc');
            self::fail('expected the id to be refused');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $hostile = $this->project("edge\nrespond 200");
        try {
            $this->writer->write($this->instance($hostile), $hostile);
            self::fail('expected the host name to be refused');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([], glob($this->paths->caddyInstancesDir().'/*') ?: []);
        self::assertSame([], $this->runner->calls());
    }

    public function testValidateOnlyValidates(): void
    {
        $this->writer->validate();

        self::assertCount(1, $this->runner->callsMatching(['caddy', 'validate']));
        self::assertSame([], $this->runner->callsMatching(['caddy', 'reload']));
    }
}
