<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Projects;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\PrivilegedApply;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A project created or deleted (through ProjectService, so by the pages and the commands alike) re-renders the Authelia
 * access rules and installs them when they changed. A failing install is a warning: the project change stands.
 */
final class ProjectAccessRefreshTest extends KernelTestCase
{
    use FleetDatabase;
    use RunsFleetCommands;

    private const string GIT = 'https://git.example.com/acme/blog.git';

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
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

    private function projects(): ProjectService
    {
        $service = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);

        return $service;
    }

    private function refresher(): ProjectAccessRefresher
    {
        $refresher = self::getContainer()->get(ProjectAccessRefresher::class);
        self::assertInstanceOf(ProjectAccessRefresher::class, $refresher);

        return $refresher;
    }

    private function completeSetup(): void
    {
        $host = new HostSettings('fleet.example.com');
        $host->markSetupComplete();
        $repository = self::getContainer()->get(HostSettingsRepository::class);
        self::assertInstanceOf(HostSettingsRepository::class, $repository);
        $repository->save($host);
    }

    private function installs(): int
    {
        return \count($this->runner()->callsMatching(['sudo', '-n', '--', PrivilegedApply::HELPER, 'authelia']));
    }

    private function exists(string $name): bool
    {
        $this->entityManager()->clear();

        return null !== self::getContainer()->get(ProjectRepository::class)->findByName($name);
    }

    public function testNothingHappensBeforeSetupIsComplete(): void
    {
        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));
        $this->projects()->delete('blog', 'tester');

        self::assertSame(0, $this->installs());
        self::assertNull($this->refresher()->takeWarning());
    }

    public function testCreateAndDeleteInstallTheRules(): void
    {
        $this->completeSetup();

        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));
        self::assertSame(1, $this->installs(), 'a new project needs its rule');

        $this->projects()->delete('blog', 'tester');
        self::assertSame(2, $this->installs(), 'a deleted project loses its rule');
        self::assertNull($this->refresher()->takeWarning());
    }

    public function testAnUpdateDoesNotRestartAuthelia(): void
    {
        $this->completeSetup();
        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));

        $this->projects()->update('blog', new ProjectInput(name: 'blog', git: 'https://git.example.com/acme/blog2.git'));

        self::assertSame(1, $this->installs());
    }

    public function testAFailedInstallIsAWarningAndTheProjectStands(): void
    {
        $this->completeSetup();
        $this->runner()->expect(['sudo'], new CommandResult(1, '', "apply: boom\n"));

        $project = $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));

        self::assertSame('blog', $project->name());
        self::assertTrue($this->exists('blog'));
        $warning = $this->refresher()->takeWarning();
        self::assertNotNull($warning);
        self::assertStringContainsString('The project was saved, but the sign-in service was not updated', $warning);
        self::assertNull($this->refresher()->takeWarning(), 'a warning is taken once');

        // The failed install was not remembered: the next project change retries it.
        $this->projects()->create(new ProjectInput(name: 'shop', git: 'https://git.example.com/acme/shop.git'));
        self::assertSame(2, $this->installs());
        self::assertNull($this->refresher()->takeWarning());
    }

    public function testProjectCommandsShowTheWarning(): void
    {
        $this->completeSetup();
        $this->runner()->expect(['sudo'], new CommandResult(1, '', "apply: boom\n"));

        $created = $this->cli('fleet:project:create', ['name' => 'blog', '--git' => self::GIT, '--json' => true]);

        self::assertSame(0, $created->getStatusCode(), $created->getDisplay());
        self::assertSame('', $created->getErrorOutput());
        self::assertStringContainsString('the sign-in service was not updated', $this->json($created)['warning']);
        self::assertTrue($this->exists('blog'));

        $this->runner()->expect(['sudo'], new CommandResult(1, '', "apply: boom\n"));
        $deleted = $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true]);

        self::assertSame(0, $deleted->getStatusCode());
        self::assertStringContainsString('Project "blog" deleted.', $deleted->getDisplay());
        self::assertStringContainsString('Warning: The project was saved', $deleted->getErrorOutput());
        self::assertFalse($this->exists('blog'));
    }

    public function testProjectCommandsStayQuietWhenTheInstallWorks(): void
    {
        $this->completeSetup();

        $created = $this->cli('fleet:project:create', ['name' => 'blog', '--git' => self::GIT, '--json' => true]);

        self::assertSame(0, $created->getStatusCode());
        self::assertArrayNotHasKey('warning', $this->json($created));
        self::assertSame(1, $this->installs());
    }

    public function testTheServiceStillWorksWithoutARefresher(): void
    {
        $em = $this->entityManager();
        $service = new ProjectService(new ProjectRepository($em), new NamedPortRepository($em), new FakeInstanceGuard());

        $service->create(new ProjectInput(name: 'blog', git: self::GIT));

        self::assertTrue($this->exists('blog'));
    }
}
