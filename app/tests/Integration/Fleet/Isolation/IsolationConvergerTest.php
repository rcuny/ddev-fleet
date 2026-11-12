<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Isolation;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\IsolationMode;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectLinkRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Isolation\DockerNetworks;
use App\Fleet\Isolation\IsolationConverger;
use App\Fleet\Isolation\IsolationReport;
use App\Fleet\Isolation\IsolationRuleset;
use App\Fleet\Isolation\LinkResolver;
use App\Fleet\Projects\ProjectLinkService;
use App\Fleet\Shell\CommandResult;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\SystemRenderer;
use App\Tests\Support\InstanceJobTestCase;
use App\Tests\Support\RecordingApplier;

/** FLE-299: one isolation pass over a migrated database with the recording runner. */
final class IsolationConvergerTest extends InstanceJobTestCase
{
    private const string BRIDGE_FORMAT = '{{.Id}}|{{index .Options "com.docker.network.bridge.name"}}';
    private const string ROUTER_FORMAT = '{{with index .NetworkSettings.Networks "ddev_default"}}{{.IPAddress}}{{end}}';
    private const string MEMBERS_FORMAT = '{{range .Containers}}{{.Name}} {{end}}';

    private RecordingApplier $applier;
    private IsolationConverger $converger;
    private HostSettingsRepository $host;
    /** @var list<string> the staged ruleset at each apply */
    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new HostSettingsRepository($this->em);
        $blog = new Project('blog', 'https://git.example.com/acme/blog.git');
        new ProjectRepository($this->em)->save($blog);
        $this->instance('staging', state: InstanceState::Running);
        $blogInstance = new Instance($blog, 'staging', 'full', 'develop', 'admin', $this->clock->now());
        $blogInstance->changeState(InstanceState::Running, $this->clock->now());
        $this->instances->createMany([$blogInstance]);
        $this->instance('main', state: InstanceState::Running);
        $this->applier = new RecordingApplier();
        $this->converger = $this->makeConverger();
        $this->scriptDocker();
    }

    private function makeConverger(): IsolationConverger
    {
        $system = self::getContainer()->get(SystemRenderer::class);
        self::assertInstanceOf(SystemRenderer::class, $system);
        $docker = new DockerNetworks($this->runner);
        $applier = new class($this->applier, $this->paths, fn (string $text) => $this->staged[] = $text) implements \App\Fleet\System\Applier {
            public function __construct(private RecordingApplier $inner, private \App\Fleet\FleetPaths $paths, private \Closure $capture)
            {
            }

            public function apply(ApplyTarget $target): void
            {
                ($this->capture)((string) file_get_contents($this->paths->stagingFile('isolation', 'fleet_isolation.nft')));
                $this->inner->apply($target);
            }
        };

        return new IsolationConverger(
            $this->host,
            new NamedPortRepository($this->em),
            new LinkResolver(new ProjectLinkRepository($this->em), $this->instances, $this->ddev),
            $docker,
            new IsolationReport($this->runner),
            $system,
            $applier,
            $this->paths,
            new AuditLog(new AuditEntryRepository($this->em), $this->clock),
            new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($this->paths->locksDir())),
            new \App\Fleet\Isolation\NftablesServiceGuard($this->runner),
        );
    }

    private function scriptDocker(string $table = '{"nftables":[]}'): void
    {
        $this->runner
            ->expect(['docker', 'network', 'inspect', '--format', self::BRIDGE_FORMAT], new CommandResult(0, "0123456789abcdef0123|\n"))
            ->expect(['docker', 'inspect', '--format', self::ROUTER_FORMAT], new CommandResult(0, "172.18.0.2\n"))
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, $table));
    }

    private function containers(string $instance, string ...$services): void
    {
        $rows = array_map(static fn (string $s): string => 'ddev-'.$instance.'-'.$s.'|'.$s.'|', $services);
        $this->runner->expect(['docker', 'ps', '-a', '--filter', 'label=com.ddev.site-name='.$instance], new CommandResult(0, implode("\n", $rows)."\n"));
    }

    private function mode(IsolationMode $mode): void
    {
        $settings = $this->host->get();
        self::assertNotNull($settings);
        $settings->changeIsolationMode($mode, new \DateTimeImmutable());
        $this->host->save($settings);
    }

    private function declareLink(): void
    {
        new ProjectLinkService(new ProjectRepository($this->em), new ProjectLinkRepository($this->em), new AuditLog(new AuditEntryRepository($this->em), $this->clock))
            ->create('acme', 'blog', 'typesense', 8108, 'same', 'search', 'admin');
    }

    public function testModeOffDoesNothingAtAll(): void
    {
        $this->runner->reset();
        $pass = $this->converger->pass();

        self::assertSame(IsolationMode::Off, $pass->mode);
        self::assertFalse($pass->applied);
        self::assertSame([], $this->runner->calls());
        self::assertSame([], $this->applier->applied);
    }

    public function testReportModeInstallsTheRulesetOnceAndOnlyOnceWhileNothingChanges(): void
    {
        $this->mode(IsolationMode::Report);
        $first = $this->converger->pass();

        self::assertTrue($first->ok(), (string) $first->error);
        self::assertTrue($first->applied);
        self::assertSame([ApplyTarget::Isolation], $this->applier->applied);
        self::assertStringContainsString('iifname "br-0123456789ab"', $this->staged[0]);
        self::assertStringContainsString('elements = { 172.18.0.2 }', $this->staged[0]);
        self::assertStringContainsString('ct state new counter accept', $this->staged[0]);
        // The staged file is gone afterwards.
        self::assertFileDoesNotExist($this->paths->stagingFile('isolation', 'fleet_isolation.nft'));

        // The second pass finds the table installed and unchanged: nothing is applied.
        $this->scriptDocker('{"nftables":[{"table":{"family":"inet","name":"fleet_isolation"}},{"set":{"name":"routers","elem":["172.18.0.2"]}}]}');
        $second = $this->converger->pass();
        self::assertTrue($second->ok());
        self::assertFalse($second->applied);
        self::assertCount(1, $this->applier->applied);
    }

    public function testAPassReappliesWhenTheTableVanishedAndKeepsTheRecordedFlows(): void
    {
        $this->mode(IsolationMode::Report);
        $this->converger->pass();
        $this->scriptDocker('{"nftables":[{"table":{"family":"inet","name":"fleet_isolation"}},{"set":{"name":"observed","elem":[{"concat":["172.18.0.5","172.18.0.6",80]}]}}]}');
        // The hash matches but the report says the table is installed: no apply.
        self::assertFalse($this->converger->pass()->applied);

        // A reboot or a manual delete: the table is gone, the ruleset is installed again.
        $this->scriptDocker();
        self::assertTrue($this->converger->pass()->applied);
        self::assertCount(2, $this->applier->applied);
    }

    public function testEnforceModeAndTheCatalogueAndHostPortsReachTheRuleset(): void
    {
        new NamedPortRepository($this->em)->save(new NamedPort('search', 9108, 8108));
        $settings = $this->host->get();
        self::assertNotNull($settings);
        $settings->changeIsolationMode(IsolationMode::Enforce, new \DateTimeImmutable());
        $settings->changeIsolationHostPorts([9003], new \DateTimeImmutable());
        $this->host->save($settings);

        $pass = $this->converger->pass();

        self::assertTrue($pass->ok(), (string) $pass->error);
        self::assertStringContainsString('ct state new counter drop', $this->staged[0]);
        self::assertStringContainsString('elements = { 80, 443, 9003, 9108 }', $this->staged[0]);
    }

    public function testADeclaredLinkCreatesAnInternalNetworkAttachesBothContainersAndAddsItsRule(): void
    {
        $this->declareLink();
        $this->mode(IsolationMode::Enforce);
        $this->containers('acme--staging', 'web', 'db');
        $this->containers('blog--staging', 'web', 'typesense');
        $this->runner->expect(['docker', 'network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}', 'fleet-link-acme--staging--blog--staging--typesense--8108'], new CommandResult(0, "ddev-acme--staging-web|172.20.0.2/16\nddev-blog--staging-typesense|172.20.0.3/16\n"));
        // acme--main has no blog--main: that pair is pending.
        $this->containers('acme--main', 'web');

        $pass = $this->converger->pass();

        self::assertTrue($pass->ok(), (string) $pass->error);
        $live = array_values(array_filter($pass->links, static fn ($l): bool => $l->isLive()));
        self::assertCount(1, $live);
        self::assertSame('acme--staging--blog--staging--typesense--8108', $live[0]->id());
        $network = 'fleet-link-acme--staging--blog--staging--typesense--8108';
        $create = $this->runner->callsMatching(['docker', 'network', 'create']);
        self::assertCount(1, $create);
        self::assertContains('--internal', $create[0]->argv);
        self::assertContains('com.docker.network.bridge.name='.$live[0]->bridge(), $create[0]->argv);
        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'connect', $network, 'ddev-acme--staging-web']));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'connect', '--alias', 'typesense.blog', $network, 'ddev-blog--staging-typesense']));
        self::assertStringContainsString('iifname "'.$live[0]->bridge().'" oifname "'.$live[0]->bridge().'" ip saddr 172.20.0.2 ip daddr 172.20.0.3 tcp dport 8108 ct state new accept', $this->staged[0]);
        $pending = array_values(array_filter($pass->links, static fn ($l): bool => !$l->isLive()));
        self::assertCount(1, $pending);
        self::assertStringContainsString('blog--main does not exist', (string) $pending[0]->pending);
    }

    public function testALinkWhoseServiceIsMissingIsPendingNotAnError(): void
    {
        $this->declareLink();
        $this->mode(IsolationMode::Report);
        $this->containers('acme--staging', 'web');
        $this->containers('blog--staging', 'web');

        $pass = $this->converger->pass();

        self::assertTrue($pass->ok());
        self::assertSame([], $this->runner->callsMatching(['docker', 'network', 'create']));
        $reasons = array_map(static fn ($l): ?string => $l->pending, $pass->links);
        self::assertContains('target has no running service typesense', $reasons);
    }

    public function testOrphanLinkNetworksAreDisconnectedAndRemoved(): void
    {
        $this->mode(IsolationMode::Report);
        $this->runner->expect(['docker', 'network', 'ls', '--filter', 'label=com.ddev-fleet.link'], new CommandResult(0, "fleet-link-old--a--x--1\nbridge\n"));
        $this->runner->expect(['docker', 'network', 'inspect', '--format', self::MEMBERS_FORMAT], new CommandResult(0, 'ddev-old-web ddev-a-x '));

        $this->converger->pass();

        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'disconnect', '--force', 'fleet-link-old--a--x--1', 'ddev-old-web']));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'rm', 'fleet-link-old--a--x--1']));
        self::assertSame([], $this->runner->callsMatching(['docker', 'network', 'rm', 'bridge']));
    }

    public function testAnUnreadableLiveTableChangesNothing(): void
    {
        $this->mode(IsolationMode::Enforce);
        $this->runner->reset();
        $this->runner
            ->expect(['docker', 'network', 'inspect', '--format', self::BRIDGE_FORMAT], new CommandResult(0, "0123456789abcdef0123|\n"))
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(1, '', 'no sudo'));

        $pass = $this->converger->pass();

        self::assertFalse($pass->ok());
        self::assertSame([], $this->applier->applied);
    }

    public function testDisablingDeletesTheTableAtOnceAndAuditsTheChange(): void
    {
        $this->mode(IsolationMode::Enforce);
        $this->converger->pass();

        $pass = $this->converger->changeMode(IsolationMode::Off, 'cli');

        self::assertSame(IsolationMode::Off, $pass->mode);
        self::assertSame(IsolationRuleset::disabled(), end($this->staged));
        self::assertSame(IsolationMode::Off, $this->host->get()?->isolationMode());
        $entries = new AuditEntryRepository($this->em)->latest(5);
        self::assertSame('isolation.mode', $entries[0]->action());
        self::assertSame('enforce -> off', $entries[0]->subject());
    }

    public function testForgettingAnInstanceRemovesItsLinkNetworks(): void
    {
        $this->runner->expect(['docker', 'network', 'ls', '--filter', 'label=com.ddev-fleet.link'], new CommandResult(0, "fleet-link-acme--main--blog--main--typesense--8108\nfleet-link-x--y--web--80\n"));
        $this->runner->expect(['docker', 'network', 'inspect', '--format', self::MEMBERS_FORMAT], new CommandResult(0, 'ddev-acme--main-web '));
        $this->runner->expect(['docker', 'network', 'inspect', '--format', self::MEMBERS_FORMAT], new CommandResult(0, ''));

        $this->converger->forgetInstance('acme--main');

        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'rm', 'fleet-link-acme--main--blog--main--typesense--8108']));
        self::assertSame([], $this->runner->callsMatching(['docker', 'network', 'rm', 'fleet-link-x--y--web--80']));
    }

    public function testAFailureAnywhereIsReturnedNeverThrown(): void
    {
        $this->mode(IsolationMode::Enforce);
        $this->applier->fails = new \LogicException('boom');
        $pass = $this->converger->pass();

        self::assertFalse($pass->ok());
        self::assertSame('boom', $pass->error);
    }

    public function testReportModeStagesNoDropAtAll(): void
    {
        $this->mode(IsolationMode::Report);
        $this->converger->pass();

        self::assertStringNotContainsString('drop', $this->staged[0]);
    }

    public function testAnEnabledNftablesServiceStopsEnableAndPasses(): void
    {
        $this->mode(IsolationMode::Enforce);
        $this->runner->expect(['systemctl', 'is-enabled', 'nftables.service'], new CommandResult(0, "enabled\n"));
        $pass = $this->converger->pass();

        self::assertFalse($pass->ok());
        self::assertStringContainsString('nftables.service is enabled', (string) $pass->error);
        self::assertSame([], $this->applier->applied);

        $this->runner->expect(['systemctl', 'is-enabled', 'nftables.service'], new CommandResult(0, "enabled\n"));
        try {
            $this->converger->changeMode(IsolationMode::Report, 'cli');
            self::fail('enable must refuse');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Fleet never enables it', $e->getMessage());
        }
        self::assertSame(IsolationMode::Enforce, $this->host->get()?->isolationMode(), 'the mode is unchanged');
    }

    public function testDisableRemovesTheTableEvenWhenALaterStepFails(): void
    {
        $this->mode(IsolationMode::Enforce);
        $this->converger->pass();
        // The audit write fails after the table was deleted: the table is gone anyway, the error is reported.
        $this->em->getConnection()->executeStatement('DROP TABLE audit_entry');

        try {
            $this->converger->changeMode(IsolationMode::Off, 'cli');
            self::fail('the failed step must be reported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not completed', $e->getMessage());
        }
        self::assertSame(IsolationRuleset::disabled(), end($this->staged));
    }

    public function testAnOffHostRetriesARemovalThatFailedBefore(): void
    {
        $this->mode(IsolationMode::Enforce);
        $this->converger->pass();
        $this->applier->fails = new \RuntimeException('helper down');
        try {
            $this->converger->changeMode(IsolationMode::Off, 'cli');
        } catch (\RuntimeException) {
        }
        self::assertSame(IsolationMode::Off, $this->host->get()?->isolationMode(), 'the mode was still saved');
        $this->applier->fails = null;
        $staged = \count($this->staged);

        $pass = $this->converger->pass();

        self::assertTrue($pass->applied);
        self::assertCount($staged + 1, $this->staged);
        self::assertSame(IsolationRuleset::disabled(), end($this->staged));
        // Nothing left to retry.
        self::assertFalse($this->converger->pass()->applied);
    }

    public function testASecondPassWhileOneRunsDoesNothing(): void
    {
        $this->mode(IsolationMode::Report);
        $lock = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($this->paths->locksDir()))->createLock('isolation.pass', 120.0);
        self::assertTrue($lock->acquire(false));
        $this->runner->reset();

        $pass = $this->converger->pass();

        self::assertTrue($pass->ok());
        self::assertFalse($pass->applied);
        self::assertSame([], $this->runner->calls());
        $lock->release();
    }

    public function testDeletingALinkRunsAPassAtOnceAndRemovesItsNetwork(): void
    {
        $this->declareLink();
        $this->mode(IsolationMode::Enforce);
        $service = new ProjectLinkService(new ProjectRepository($this->em), new ProjectLinkRepository($this->em), new AuditLog(new AuditEntryRepository($this->em), $this->clock), $this->converger);
        // The network of the link exists; after the delete no link wants it.
        $this->runner->expect(['docker', 'network', 'ls', '--filter', 'label=com.ddev-fleet.link'], new CommandResult(0, "fleet-link-acme--staging--blog--staging--typesense--8108\n"));
        $this->runner->expect(['docker', 'network', 'inspect', '--format', self::MEMBERS_FORMAT], new CommandResult(0, 'ddev-acme--staging-web ddev-blog--staging-typesense '));

        $service->delete('acme', 'blog', 'typesense', 8108, 'same', 'admin');

        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'rm', 'fleet-link-acme--staging--blog--staging--typesense--8108']));
        self::assertCount(2, $this->runner->callsMatching(['docker', 'network', 'disconnect', '--force']));
    }
}
