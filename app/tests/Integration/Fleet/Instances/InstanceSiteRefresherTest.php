<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Port\NamedPort;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Instances\InstanceSiteWriter;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\CaddyReloader;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\SystemTemplates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class InstanceSiteRefresherTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;

    private EntityManagerInterface $em;
    private FleetPaths $paths;
    private RecordingCommandRunner $runner;
    private InstanceSiteWriter $writer;
    private InstanceSiteRefresher $refresher;
    private ProjectService $service;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $host = new HostSettingsRepository($this->em);
        $host->getOrCreate('fleet.example.com');
        $this->runner = new RecordingCommandRunner();
        $this->writer = new InstanceSiteWriter(
            SystemTemplates::twig(),
            $this->paths,
            new CaddyReloader($this->runner, $this->paths),
            new InstanceHostnames($host),
            new LockFactory(new FlockStore($this->paths->locksDir())),
            new AdminGroup('fleet-admins'),
        );
        $this->refresher = new InstanceSiteRefresher(new InstanceRepository($this->em), $this->writer, $this->paths);
        $this->service = new ProjectService(new ProjectRepository($this->em), new NamedPortRepository($this->em), new FakeInstanceGuard(), null, null, $this->refresher);
        new NamedPortRepository($this->em)->save(new NamedPort('typesense', 9108, 8108));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function site(string $id): string
    {
        return (string) file_get_contents($this->paths->instanceSiteFile($id));
    }

    /**
     * Two projects with a deployed instance each (site written without ports), plus a failed instance without a site.
     */
    private function deploy(): void
    {
        foreach (['acme' => [InstanceState::Running, InstanceState::Failed], 'other' => [InstanceState::Running]] as $name => $states) {
            $project = $this->projectNamed($name);
            [$instance] = $this->instancesOf($project, $states);
            $this->writer->write($instance, $project);
        }
        $this->runner->reset();
    }

    private function input(string ...$ports): ProjectInput
    {
        return ProjectInput::fromProject($this->projectNamed('acme'))->with(['ports' => array_values($ports)]);
    }

    public function testAssigningAPortRewritesOnlyThatProjectsSites(): void
    {
        $this->deploy();
        $otherBefore = $this->site('other--i1');

        $this->service->update('acme', $this->input('typesense'));

        self::assertStringContainsString('acme--i1.fleet.example.com:9108 {', $this->site('acme--i1'));
        self::assertSame($otherBefore, $this->site('other--i1'));
        self::assertCount(1, $this->runner->callsMatching(['caddy', 'reload']), 'one site rewritten, one reload');
        self::assertNull($this->refresher->takeWarning());
    }

    public function testUnassigningTakesThePortBlockAway(): void
    {
        $this->deploy();
        $this->service->update('acme', $this->input('typesense'));
        $this->runner->reset();

        $this->service->update('acme', $this->input());

        self::assertStringNotContainsString(':9108', $this->site('acme--i1'));
        self::assertCount(1, $this->runner->callsMatching(['caddy', 'reload']));
    }

    public function testASaveThatLeavesThePortsAloneRewritesNothing(): void
    {
        $this->deploy();

        $this->service->update('acme', $this->input());

        self::assertSame([], $this->runner->calls());
    }

    public function testInstancesWithoutASiteGetNone(): void
    {
        $this->deploy();

        $result = $this->refresher->refresh();

        self::assertSame(2, $result->rewritten);
        self::assertFileDoesNotExist($this->paths->instanceSiteFile('acme--i2'));
    }

    public function testACaddyRefusalRollsBackKeepsTheAssignmentAndWarns(): void
    {
        $this->deploy();
        $before = $this->site('acme--i1');
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: bad config'));

        $this->service->update('acme', $this->input('typesense'));

        self::assertSame($before, $this->site('acme--i1'));
        $warning = $this->refresher->takeWarning();
        self::assertNotNull($warning);
        self::assertStringContainsString('acme--i1', $warning);
        self::assertStringContainsString('bad config', $warning);
        self::assertNull($this->refresher->takeWarning(), 'a warning is taken once');
        $this->em->clear();
        self::assertCount(1, $this->projectNamed('acme')->ports(), 'the assignment itself stays saved');
    }

    public function testRefreshOfOneProjectReportsTheFailures(): void
    {
        $this->deploy();
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: bad config'));

        $result = $this->refresher->refresh($this->projectNamed('acme'));

        self::assertSame(0, $result->rewritten);
        self::assertSame(['acme--i1'], array_keys($result->failed));
    }
}
