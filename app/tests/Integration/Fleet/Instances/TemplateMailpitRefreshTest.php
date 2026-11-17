<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\ProjectRepository;
use App\Domain\Template\TemplateRepository;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Instances\InstanceSiteRefresher;
use App\Fleet\Instances\InstanceSiteWriter;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\ExtraSshKey;
use App\Fleet\Ssh\SshKeysLock;
use App\Fleet\System\CaddyReloader;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\SystemTemplates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * FLE-346: turning a template's Mailpit option on or off rewrites the Caddy sites of the project's existing instances.
 */
final class TemplateMailpitRefreshTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;

    private EntityManagerInterface $em;
    private FleetPaths $paths;
    private RecordingCommandRunner $runner;
    private InstanceSiteWriter $writer;
    private TemplateService $service;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $host = new HostSettingsRepository($this->em);
        $host->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $this->runner = new RecordingCommandRunner();
        $templates = new TemplateRepository($this->em);
        $this->writer = new InstanceSiteWriter(
            SystemTemplates::twig(),
            $this->paths,
            new CaddyReloader($this->runner, $this->paths),
            new InstanceHostnames($host),
            new LockFactory(new FlockStore($this->paths->locksDir())),
            new AdminGroup('fleet-admins'),
            new ProjectRepository($this->em),
            new \App\Fleet\Instances\MailpitExposure($templates, new \App\Fleet\Instances\InstanceIsolation($templates, new \App\Fleet\Jobs\RegisteredSecretsMasker(), $this->runner, 'test-secret'), new InstanceHostnames($host), new \App\Fleet\Jobs\RegisteredSecretsMasker()),
        );
        $refresher = new InstanceSiteRefresher(new InstanceRepository($this->em), $this->writer, $this->paths);
        $this->service = new TemplateService(new ProjectRepository($this->em), $templates, new ExtraSshKey($this->paths), new SshKeysLock($this->paths), null, null, $refresher);
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

    /** A project with a deployed instance on the template "full" (Mailpit off) and its site written. */
    private function deploy(): void
    {
        $project = $this->projectNamed('acme');
        $this->service->create('acme', new TemplateInput('full'));
        [$instance] = $this->instancesOf($project, [InstanceState::Running]);
        $this->writer->write($instance, $project);
        $this->runner->reset();
    }

    private function change(TemplateInput $input): void
    {
        $this->service->update('acme', 'full', $input);
    }

    private function current(): TemplateInput
    {
        return TemplateInput::fromTemplate($this->service->get('acme', 'full'));
    }

    public function testTurningMailpitOnAddsTheRouteAndOffRemovesIt(): void
    {
        $this->deploy();
        self::assertStringNotContainsString('mailpit--acme--i1', $this->site('acme--i1'));

        $this->change($this->current()->with(['mailpit' => true]));

        self::assertStringContainsString("\nmailpit--acme--i1.fleet.example.com {\n", $this->site('acme--i1'));
        self::assertCount(1, $this->runner->callsMatching(['caddy', 'reload']));
        self::assertNull($this->service->takeWarning());

        $this->change($this->current()->with(['mailpit' => false]));

        self::assertStringNotContainsString('mailpit--acme--i1', $this->site('acme--i1'));
        self::assertNull($this->service->takeWarning());
    }

    public function testNoRefreshWhenMailpitDidNotChange(): void
    {
        $this->deploy();

        $this->change($this->current()->with(['tty1' => ['npm run watch']]));

        self::assertSame([], $this->runner->callsMatching(['caddy', 'validate']));
        self::assertSame([], $this->runner->callsMatching(['caddy', 'reload']));
        self::assertNull($this->service->takeWarning());
    }

    public function testACaddyRefusalKeepsTheTemplateChangeAndWarns(): void
    {
        $this->deploy();
        $before = $this->site('acme--i1');
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: bad config'));

        $this->change($this->current()->with(['mailpit' => true]));

        self::assertSame($before, $this->site('acme--i1'));
        $warning = $this->service->takeWarning();
        self::assertNotNull($warning);
        self::assertStringContainsString('acme--i1', $warning, 'the refresher\'s own warning is passed on');
        self::assertStringContainsString('bad config', $warning);
        self::assertStringContainsString('fleet port:refresh --project=acme', $warning);
        self::assertNull($this->service->takeWarning(), 'a warning is taken once');
        $this->em->clear();
        self::assertTrue($this->service->get('acme', 'full')->hasMailpit(), 'the template change itself stays saved');
    }

    public function testRefreshFalseLeavesTheSitesAloneForTheImportTransaction(): void
    {
        $this->deploy();
        $before = $this->site('acme--i1');

        $this->service->update('acme', 'full', $this->current()->with(['mailpit' => true]), false);

        self::assertSame($before, $this->site('acme--i1'));
        self::assertSame([], $this->runner->callsMatching(['caddy', 'validate']));
        self::assertSame([], $this->runner->callsMatching(['caddy', 'reload']));
        self::assertNull($this->service->takeWarning());
        $this->em->clear();
        self::assertTrue($this->service->get('acme', 'full')->hasMailpit(), 'the template change is saved');
    }

    public function testAWarningDoesNotLeakIntoALaterUpdate(): void
    {
        $this->deploy();
        $this->runner->expect(['caddy', 'validate'], new CommandResult(1, '', 'Error: bad config'));
        $this->change($this->current()->with(['mailpit' => true]));

        // the warning of the failed refresh was not taken; the next update (nothing changes Mailpit) clears it
        $this->change($this->current()->with(['tty1' => ['npm run watch']]));

        self::assertNull($this->service->takeWarning());
    }
}
