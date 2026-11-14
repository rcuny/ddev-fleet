<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\IsolationMode;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\PrivilegedApply;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** FLE-299: fleet host:isolation:status|enable|disable|check. */
final class HostIsolationCommandTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private const string BRIDGE_FORMAT = '{{.Id}}|{{index .Options "com.docker.network.bridge.name"}}';
    private const string ROUTER_FORMAT = '{{with index .NetworkSettings.Networks "ddev_default"}}{{.IPAddress}}{{end}}';

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        file_put_contents($this->tempHome.'/test-apply-helper', "#!/bin/bash\nALLOWED_TARGETS='caddy authelia slices provision drift isolation'\n");
        mkdir($this->tempHome.'/test-bridge-nf', 0o700, true);
        file_put_contents($this->tempHome.'/test-bridge-nf/bridge-nf-call-iptables', "1\n");
        file_put_contents($this->tempHome.'/test-bridge-nf/bridge-nf-call-ip6tables', "1\n");
        new HostSettingsRepository($em)->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $projects = new ProjectRepository($em);
        foreach (['app', 'cms'] as $name) {
            $project = new Project($name, 'https://git.example.com/x/'.$name.'.git');
            $projects->save($project);
            $instance = new Instance($project, 'staging', 'full', 'develop', 'admin');
            $instance->changeState(InstanceState::Running);
            new InstanceRepository($em)->createMany([$instance]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    private function docker(string $table = '{"nftables":[]}'): void
    {
        $this->runner()
            ->expect(['docker', 'network', 'inspect', '--format', self::BRIDGE_FORMAT], new CommandResult(0, "0123456789abcdef0123|\n"))
            ->expect(['docker', 'inspect', '--format', self::ROUTER_FORMAT], new CommandResult(0, "172.18.0.2\n"))
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, $table));
    }

    private function mode(): IsolationMode
    {
        $this->entityManager()->clear();

        return new HostSettingsRepository($this->entityManager())->get()?->isolationMode() ?? IsolationMode::Off;
    }

    public function testStatusOnAFreshHostSaysOffAndExitsZero(): void
    {
        $this->docker();
        $tester = $this->cli('fleet:host:isolation:status', ['--json' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $doc = $this->json($tester);
        self::assertSame('off', $doc['mode']);
        self::assertTrue($doc['ok']);
        self::assertSame([], $doc['links']);
    }

    public function testStatusDropsFlowsRecordedAtAnAddressNoContainerOwnsAnyMore(): void
    {
        $this->docker();
        self::assertSame(0, $this->cli('fleet:host:isolation:enable', ['--mode' => 'report'])->getStatusCode());
        $table = json_encode(['nftables' => [
            ['table' => ['family' => 'inet', 'name' => 'fleet_isolation']],
            ['set' => ['name' => 'routers', 'elem' => ['172.18.0.2']]],
            ['set' => ['name' => 'observed', 'elem' => [
                ['concat' => ['172.18.0.5', '172.18.0.6', 8108]],
                ['concat' => ['172.18.0.99', '172.18.0.5', 80]],
            ]]],
        ]], \JSON_THROW_ON_ERROR);
        $this->runner()
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, $table))
            ->expect(['docker', 'network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}'], new CommandResult(0, "ddev-app--staging-web|172.18.0.5/16\nddev-cms--staging-typesense|172.18.0.6/16\n"));
        $doc = $this->json($this->cli('fleet:host:isolation:status', ['--json' => true]));

        self::assertCount(1, (array) $doc['flows']);
    }

    public function testStatusDropsFlowsWhoseTargetIsStale(): void
    {
        $this->docker();
        self::assertSame(0, $this->cli('fleet:host:isolation:enable', ['--mode' => 'report'])->getStatusCode());
        $this->runner()
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, $this->table([['172.18.0.5', '172.18.0.6', 8108], ['172.18.0.5', '172.18.0.99', 80]])))
            ->expect(['docker', 'network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}'], new CommandResult(0, "ddev-app--staging-web|172.18.0.5/16\nddev-cms--staging-typesense|172.18.0.6/16\n"));
        $doc = $this->json($this->cli('fleet:host:isolation:status', ['--json' => true]));

        $flows = (array) $doc['flows'];
        self::assertCount(1, $flows);
        self::assertSame('cms--staging/typesense', $flows[0]['target']);
    }

    public function testStatusKeepsEveryFlowWhenNoContainerAddressIsKnown(): void
    {
        $this->docker();
        self::assertSame(0, $this->cli('fleet:host:isolation:enable', ['--mode' => 'report'])->getStatusCode());
        $this->runner()
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, $this->table([['172.18.0.5', '172.18.0.6', 8108], ['172.18.0.99', '172.18.0.5', 80]])))
            ->expect(['docker', 'network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}'], new CommandResult(0, ''));
        $doc = $this->json($this->cli('fleet:host:isolation:status', ['--json' => true]));

        $flows = (array) $doc['flows'];
        self::assertCount(2, $flows);
        self::assertSame('172.18.0.99', $flows[1]['source']);
    }

    /** @param list<array{0: string, 1: string, 2: int}> $observed */
    private function table(array $observed): string
    {
        return json_encode(['nftables' => [
            ['table' => ['family' => 'inet', 'name' => 'fleet_isolation']],
            ['set' => ['name' => 'routers', 'elem' => ['172.18.0.2']]],
            ['set' => ['name' => 'observed', 'elem' => array_map(static fn (array $f): array => ['concat' => $f], $observed)]],
        ]], \JSON_THROW_ON_ERROR);
    }

    public function testEnableReportAppliesAndStatusListsTheFlowsWithTheLinkThatWouldAllowThem(): void
    {
        $this->docker();
        $enabled = $this->cli('fleet:host:isolation:enable', ['--mode' => 'report']);
        self::assertSame(0, $enabled->getStatusCode(), $enabled->getDisplay());
        self::assertSame(IsolationMode::Report, $this->mode());

        $table = json_encode(['nftables' => [
            ['table' => ['family' => 'inet', 'name' => 'fleet_isolation']],
            ['set' => ['name' => 'routers', 'elem' => ['172.18.0.2']]],
            ['set' => ['name' => 'observed', 'elem' => [['concat' => ['172.18.0.5', '172.18.0.6', 8108]]]]],
        ]], \JSON_THROW_ON_ERROR);
        $this->runner()
            ->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, $table))
            ->expect(['docker', 'network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}'], new CommandResult(0, "ddev-app--staging-web|172.18.0.5/16\nddev-cms--staging-typesense|172.18.0.6/16\n"));
        $status = $this->cli('fleet:host:isolation:status', ['--json' => true]);

        $doc = $this->json($status);
        self::assertSame('report', $doc['mode']);
        $flows = (array) $doc['flows'];
        self::assertSame('app--staging', $flows[0]['source']);
        self::assertSame('cms--staging/typesense', $flows[0]['target']);
        self::assertSame('fleet project:link:create app cms --service=typesense --port=8108', $flows[0]['suggestion']);
        self::assertFalse($flows[0]['covered']);
    }

    public function testEnforceRefusesWithoutYesAndPrintsWhatItWouldCut(): void
    {
        $table = json_encode(['nftables' => [
            ['table' => ['family' => 'inet', 'name' => 'fleet_isolation']],
            ['set' => ['name' => 'observed', 'elem' => [['concat' => ['172.18.0.5', '172.18.0.6', 8108]]]]],
        ]], \JSON_THROW_ON_ERROR);
        $this->docker($table);
        $this->runner()->expect(['docker', 'network', 'inspect', '--format', '{{range .Containers}}{{.Name}}|{{.IPv4Address}}'."\n".'{{end}}'], new CommandResult(0, "ddev-app--staging-web|172.18.0.5/16\nddev-cms--staging-typesense|172.18.0.6/16\n"));

        $refused = $this->cli('fleet:host:isolation:enable', ['--mode' => 'enforce']);

        self::assertSame(1, $refused->getStatusCode());
        self::assertStringContainsString('would cut: app--staging -> cms--staging/typesense:8108', $refused->getDisplay());
        self::assertStringContainsString('needs --yes', $refused->getDisplay());
        self::assertSame(IsolationMode::Off, $this->mode());

        $this->docker();
        // enforce reads the table twice (the dry run, then the pass).
        $this->runner()->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, '{"nftables":[]}'));
        $go = $this->cli('fleet:host:isolation:enable', ['--mode' => 'enforce', '--yes' => true]);
        self::assertSame(0, $go->getStatusCode(), $go->getDisplay());
        self::assertSame(IsolationMode::Enforce, $this->mode());
    }

    public function testDisableSetsOffAndEnableRefusesOffAndUnknownModes(): void
    {
        $this->docker();
        $this->cli('fleet:host:isolation:enable', ['--mode' => 'report']);

        self::assertSame(0, $this->cli('fleet:host:isolation:disable')->getStatusCode());
        self::assertSame(IsolationMode::Off, $this->mode());
        self::assertSame(2, $this->cli('fleet:host:isolation:enable', ['--mode' => 'off'])->getStatusCode());
        self::assertSame(2, $this->cli('fleet:host:isolation:enable', ['--mode' => 'sideways'])->getStatusCode());
    }

    public function testCheckFailsOnUnexpectedReachabilityAndPassesOtherwise(): void
    {
        // Report mode expects everything reachable: the recording runner's `docker exec` succeeds for every probe.
        $this->docker();
        $this->cli('fleet:host:isolation:enable', ['--mode' => 'report']);
        $this->runner()->expect(['docker', 'network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Gateway}} {{end}}'], new CommandResult(0, "172.18.0.1 \n"));
        $report = $this->cli('fleet:host:isolation:check', ['--json' => true]);
        self::assertSame(0, $report->getStatusCode(), $report->getDisplay());
        $probes = (array) $this->json($report)['probes'];
        self::assertNotSame([], $probes);

        // Enforce expects the other instance and the gateway ssh to be blocked: every probe that "connects" is a failure.
        $this->docker();
        $this->runner()->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, '{"nftables":[]}'));
        $go = $this->cli('fleet:host:isolation:enable', ['--mode' => 'enforce', '--yes' => true]);
        self::assertSame(0, $go->getStatusCode(), $go->getDisplay());
        $this->runner()->expect(['docker', 'network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Gateway}} {{end}}'], new CommandResult(0, "172.18.0.1 \n"));
        $enforced = $this->cli('fleet:host:isolation:check', ['--json' => true]);
        self::assertSame(1, $enforced->getStatusCode());
        $failed = array_filter((array) $this->json($enforced)['probes'], static fn (array $p): bool => !$p['ok']);
        self::assertContains('host sshd 172.18.0.1', array_column($failed, 'to'));
        self::assertContains('router (traefik api)', array_column($failed, 'to'));
        self::assertNotContains('internet', array_column($failed, 'to'));
        self::assertContains('cms--staging/web', array_column($failed, 'to'));
    }

    public function testCheckNeverFailsOnWhatOffAndReportDoNotPromiseAndNeverProbesDbOrTypesense(): void
    {
        $this->docker();
        $report = $this->cli('fleet:host:isolation:check', ['--json' => true]);

        self::assertSame(0, $report->getStatusCode(), $report->getDisplay());
        $probes = (array) $this->json($report)['probes'];
        $targets = implode(' ', array_column($probes, 'to'));
        self::assertStringNotContainsString('/db', $targets);
        self::assertStringNotContainsString('typesense', $targets);
        foreach ($probes as $p) {
            if ('internet' !== $p['to']) {
                self::assertSame('any', $p['expected'], (string) $p['to']);
            }
        }
    }

    public function testOffModeDoesNotFailOnABlockedLiveLinkButReportModeDoes(): void
    {
        // FLE-340: with isolation off the link's dedicated network does not exist yet, so the alias does not resolve.
        $this->cli('fleet:project:link:create', ['source' => 'app', 'target' => 'cms', '--service' => 'web', '--port' => '80', '--format' => 'json']);
        $ps = function (): void {
            foreach (['app--staging', 'cms--staging'] as $id) {
                $this->runner()->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-web|web|com.ddev.site-name='.$id."\n"));
            }
        };
        $blocked = static fn (): array => ['docker', 'exec', 'ddev-app--staging-web', 'timeout', '3', 'bash', '-c', 'exec 3<>/dev/tcp/$0/$1', 'web.cms', '80'];

        $this->docker();
        $ps();
        $this->runner()->expect($blocked(), new CommandResult(1));
        $off = $this->cli('fleet:host:isolation:check', ['--json' => true]);
        self::assertSame(0, $off->getStatusCode(), $off->getDisplay());
        $row = array_values(array_filter((array) $this->json($off)['probes'], static fn (array $p): bool => str_starts_with((string) $p['to'], 'link ')));
        self::assertCount(1, $row);
        self::assertSame('any', $row[0]['expected']);
        self::assertSame('blocked', $row[0]['actual']);

        $this->docker();
        $this->cli('fleet:host:isolation:enable', ['--mode' => 'report']);
        $this->runner()->expect(['docker', 'network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Gateway}} {{end}}'], new CommandResult(0, "172.18.0.1 \n"));
        $ps();
        $this->runner()->expect($blocked(), new CommandResult(1));
        $report = $this->cli('fleet:host:isolation:check', ['--json' => true]);
        self::assertSame(1, $report->getStatusCode());
        $failed = array_filter((array) $this->json($report)['probes'], static fn (array $p): bool => !$p['ok']);
        self::assertSame(['link cms--staging/web'], array_column($failed, 'to'));
    }

    public function testEnforceModeStillFailsOnABlockedLiveLink(): void
    {
        $this->cli('fleet:project:link:create', ['source' => 'app', 'target' => 'cms', '--service' => 'web', '--port' => '80', '--format' => 'json']);
        $this->docker();
        $this->runner()->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, '{"nftables":[]}'));
        $go = $this->cli('fleet:host:isolation:enable', ['--mode' => 'enforce', '--yes' => true]);
        self::assertSame(0, $go->getStatusCode(), $go->getDisplay());
        $this->runner()->expect(['docker', 'network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Gateway}} {{end}}'], new CommandResult(0, "172.18.0.1 \n"));
        foreach (['app--staging', 'cms--staging'] as $id) {
            $this->runner()->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-web|web|com.ddev.site-name='.$id."\n"));
        }
        $this->runner()->expect(['docker', 'exec', 'ddev-app--staging-web', 'timeout', '3', 'bash', '-c', 'exec 3<>/dev/tcp/$0/$1', 'web.cms', '80'], new CommandResult(1));

        $enforced = $this->cli('fleet:host:isolation:check', ['--json' => true]);

        self::assertSame(1, $enforced->getStatusCode());
        $link = array_values(array_filter((array) $this->json($enforced)['probes'], static fn (array $p): bool => 'link cms--staging/web' === $p['to']));
        self::assertSame('reachable', $link[0]['expected']);
        self::assertFalse($link[0]['ok']);
    }

    public function testBridgeNetfilterMissingFailsTheCheckAndRefusesEnable(): void
    {
        unlink($this->tempHome.'/test-bridge-nf/bridge-nf-call-iptables');
        $this->docker();
        $this->runner()->expect(['sudo', '-n', '--', PrivilegedApply::HELPER, 'isolation'], new CommandResult(0, '{"nftables":[]}'));

        $enable = $this->cli('fleet:host:isolation:enable', ['--mode' => 'enforce', '--yes' => true]);

        self::assertSame(1, $enable->getStatusCode());
        self::assertStringContainsString('br_netfilter is not active', $enable->getDisplay());
        self::assertSame(IsolationMode::Off, $this->mode(), 'the mode is unchanged');
    }
}
