<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Projects;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\ProjectAccessRefresher;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\PrivilegedApply;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RunsFleetCommands;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A project created or deleted (through ProjectService, so by the pages and the commands alike) rewrites users.yml (its
 * group goes) and never installs Authelia's configuration, which would restart Authelia and sign everyone out. A
 * failing write is a warning: the project change stands.
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

    public function testCreateAndDeleteNeverRestartAuthelia(): void
    {
        $this->completeSetup();

        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));
        $this->projects()->delete('blog', 'tester');

        self::assertSame(0, $this->installs(), 'project access is a users.yml group: `apply authelia` would sign everyone out');
        self::assertNull($this->refresher()->takeWarning());
    }

    public function testDeletingAProjectRemovesItsGroupFromTheUsersFile(): void
    {
        $this->completeSetup();
        $users = self::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $users->upsertAdmin('bob', 'Bob', 'bob@example.com', 'correct horse battery staple');
        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));
        $access = self::getContainer()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $access);
        $access->grant('bob', 'blog');
        $identity = self::getContainer()->get(IdentitySync::class);
        self::assertInstanceOf(IdentitySync::class, $identity);
        $identity->refreshAccess();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        self::assertContains('fleet-project-blog', Yaml::parseFile($paths->usersFile())['users']['bob']['groups']);

        $this->projects()->delete('blog', 'tester');

        self::assertNotContains('fleet-project-blog', Yaml::parseFile($paths->usersFile())['users']['bob']['groups']);
        self::assertSame(0, $this->installs());
    }

    public function testAnUpdateDoesNotRestartAuthelia(): void
    {
        $this->completeSetup();
        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));

        $this->projects()->update('blog', new ProjectInput(name: 'blog', git: 'https://git.example.com/acme/blog2.git'));

        self::assertSame(0, $this->installs());
    }

    private function breakUsersFile(): string
    {
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        // A directory where users.yml should be: the atomic rename cannot replace it.
        if (is_file($paths->usersFile())) {
            unlink($paths->usersFile());
        }
        mkdir($paths->usersFile(), 0o750, true);

        return $paths->usersFile();
    }

    public function testAFailedUsersFileWriteIsAWarningAndTheProjectStands(): void
    {
        $this->completeSetup();
        $this->projects()->create(new ProjectInput(name: 'blog', git: self::GIT));
        $file = $this->breakUsersFile();

        $this->projects()->delete('blog', 'tester');

        self::assertFalse($this->exists('blog'));
        $warning = $this->refresher()->takeWarning();
        self::assertNotNull($warning);
        self::assertStringContainsString('The project was saved, but the sign-in service was not updated', $warning);
        self::assertNull($this->refresher()->takeWarning(), 'a warning is taken once');
        rmdir($file);
    }

    public function testProjectCommandsShowTheWarning(): void
    {
        $this->completeSetup();
        $file = $this->breakUsersFile();

        $created = $this->cli('fleet:project:create', ['name' => 'blog', '--git' => self::GIT, '--json' => true]);
        self::assertSame(0, $created->getStatusCode(), $created->getDisplay());
        self::assertTrue($this->exists('blog'));

        $deleted = $this->cli('fleet:project:delete', ['name' => 'blog', '--yes' => true]);

        self::assertSame(0, $deleted->getStatusCode());
        self::assertStringContainsString('Project "blog" deleted.', $deleted->getDisplay());
        self::assertStringContainsString('Warning: The project was saved', $deleted->getErrorOutput());
        self::assertFalse($this->exists('blog'));
        rmdir($file);
    }

    public function testProjectCommandsStayQuietWhenTheWriteWorks(): void
    {
        $this->completeSetup();

        $created = $this->cli('fleet:project:create', ['name' => 'blog', '--git' => self::GIT, '--json' => true]);

        self::assertSame(0, $created->getStatusCode());
        self::assertArrayNotHasKey('warning', $this->json($created));
        self::assertSame(0, $this->installs());
    }

    public function testTheServiceStillWorksWithoutARefresher(): void
    {
        $em = $this->entityManager();
        $service = new ProjectService(new ProjectRepository($em), new NamedPortRepository($em), new FakeInstanceGuard());

        $service->create(new ProjectInput(name: 'blog', git: self::GIT));

        self::assertTrue($this->exists('blog'));
    }
}
