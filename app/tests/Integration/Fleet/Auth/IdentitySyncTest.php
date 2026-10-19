<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Port\NamedPortRepository;
use App\Domain\Project\ProjectAccessRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\Secret\SecretRepository;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AccessInstallFailed;
use App\Fleet\Auth\AdminGroup;
use App\Fleet\Auth\AutheliaAccessSync;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Fleet\Auth\IdentitySync;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\ProjectAccessRules;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretBox;
use App\Fleet\Secrets\SecretKeyFile;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\AutheliaConfigRenderer;
use App\Fleet\System\CaddyEnvRenderer;
use App\Fleet\System\CaddyfileRenderer;
use App\Fleet\System\CaddyReloader;
use App\Fleet\System\GateWriter;
use App\Fleet\System\SystemRenderer;
use App\Tests\Support\FakeInstanceGuard;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\RecordingApplier;
use App\Tests\Support\SystemTemplates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Yaml\Yaml;

/**
 * IdentitySync (users + memberships -> users.yml in one transaction, then the Authelia rules) and AutheliaAccessSync
 * (install the rules only when they changed, only after setup).
 */
final class IdentitySyncTest extends KernelTestCase
{
    use FleetDatabase;

    private const string PASSWORD = 'correct horse battery staple';

    private FleetPaths $paths;
    private HostSettingsRepository $settings;
    private UserRepository $users;
    private UserService $accounts;
    private ProjectAccessService $access;
    private RecordingApplier $applier;
    private AutheliaAccessSync $accessSync;
    private IdentitySync $sync;

    protected function setUp(): void
    {
        $em = $this->setUpFleetDatabase();
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        $this->paths = $paths;
        $this->settings = new HostSettingsRepository($em);
        $this->users = new UserRepository($em);
        $projects = new ProjectRepository($em);
        $accessRepository = new ProjectAccessRepository($em);
        $admins = new AdminGroup('fleet-admins');
        $this->accounts = new UserService($this->users, new PasswordPolicy(), $admins);
        $this->access = new ProjectAccessService($this->users, $projects, $accessRepository);
        $this->applier = new RecordingApplier();

        $twig = SystemTemplates::twig();
        $config = new AutheliaConfigRenderer($twig, $paths, new ProjectAccessRules($accessRepository, $admins));
        $runner = new RecordingCommandRunner();
        $store = new SecretStore(new SecretRepository($em), new SecretBox(new SecretKeyFile($paths)), new MockClock('2026-10-19 10:00:00 UTC'));
        $system = new SystemRenderer(
            new CaddyfileRenderer($twig, $paths, '/opt/ddev-fleet/app'),
            new CaddyEnvRenderer($store, 'proxy-secret-0123456789abcdef-0123456789'),
            $config,
            new GateWriter($twig, $paths, new CaddyReloader($runner, $paths)),
            $paths,
            $this->applier,
        );
        $this->accessSync = new AutheliaAccessSync($this->settings, $config, $system, $paths, new LockFactory(new FlockStore($paths->locksDir())));
        $this->sync = new IdentitySync($this->users, new AutheliaUsersFile($paths, new AutheliaUsersRenderer()), $this->accessSync);

        $this->accounts->upsertAdmin('alice', 'Alice', 'alice@example.com', self::PASSWORD);
        $this->accounts->upsertAdmin('bob', 'Bob', 'bob@example.com', self::PASSWORD);
        $projectService = new ProjectService($projects, new NamedPortRepository($em), new FakeInstanceGuard());
        foreach (['blog', 'demo'] as $name) {
            $projectService->create(new ProjectInput(name: $name, git: 'https://git.example.com/acme/'.$name.'.git'));
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function completeSetup(): void
    {
        $host = new HostSettings('fleet.example.com');
        $host->markSetupComplete();
        $this->settings->save($host);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function installedRules(): array
    {
        // The helper removes staged files after each apply; the recorder does not run it, so read what was rendered.
        $rendered = Yaml::parse(self::getContainer()->get(AutheliaConfigRenderer::class)->render($this->host()));

        $rules = [];
        foreach (\array_slice($rendered['access_control']['rules'], 2) as $rule) {
            self::assertIsArray($rule);
            $rules[] = $rule;
        }

        return $rules;
    }

    private function host(): HostSettings
    {
        $host = $this->settings->get();
        self::assertNotNull($host);

        return $host;
    }

    public function testNothingIsInstalledBeforeSetupIsComplete(): void
    {
        self::assertFalse($this->accessSync->sync());

        $this->settings->save(new HostSettings('fleet.example.com'));
        self::assertFalse($this->accessSync->sync());
        self::assertSame([], $this->applier->applied);
        self::assertFileDoesNotExist($this->paths->autheliaConfigMarker());
    }

    public function testInstallsOnceAndOnlyAgainWhenTheRulesChange(): void
    {
        $this->completeSetup();

        self::assertTrue($this->accessSync->sync(), 'the first install');
        self::assertFalse($this->accessSync->sync(), 'nothing changed: no restart of Authelia');
        self::assertSame([ApplyTarget::Authelia], $this->applier->applied);

        $this->access->grant('bob', 'blog');
        self::assertTrue($this->accessSync->sync());
        self::assertFalse($this->accessSync->sync());
        self::assertSame([ApplyTarget::Authelia, ApplyTarget::Authelia], $this->applier->applied);

        self::assertTrue($this->accessSync->sync(true), 'force installs anyway');
        self::assertCount(3, $this->applier->applied);
        self::assertSame(0o600, fileperms($this->paths->autheliaConfigMarker()) & 0o777);
    }

    public function testAFailedInstallIsRetriedByTheNextCall(): void
    {
        $this->completeSetup();
        $this->applier->fails = new \RuntimeException('apply authelia failed (exit 1)');

        try {
            $this->accessSync->sync();
            self::fail('Expected the failure to surface.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('apply authelia failed', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->paths->autheliaConfigMarker(), 'a failed install is not remembered');
        self::assertSame([], glob($this->paths->stagingDir().'/authelia/*') ?: [], 'nothing stays staged');

        $this->applier->fails = null;
        self::assertTrue($this->accessSync->sync());
    }

    public function testCommitWritesUsersAndInstallsTheRulesAfterTheCommit(): void
    {
        $this->completeSetup();
        $this->accessSync->sync();
        $this->applier->applied = [];

        $this->sync->commit(function (): void {
            $this->access->grant('bob', 'demo');
        });

        $users = Yaml::parseFile($this->paths->usersFile())['users'];
        self::assertSame(['alice', 'bob'], array_keys($users));
        self::assertSame([ApplyTarget::Authelia], $this->applier->applied);
        $rules = $this->installedRules();
        self::assertSame([['group:fleet-admins']], $rules[0]['subject']);
        self::assertSame([['group:fleet-admins'], ['user:bob']], $rules[1]['subject']);
        self::assertSame(['demo'], $this->access->projectsOf('bob'));
    }

    public function testCommitReturnsWhatTheChangeReturned(): void
    {
        self::assertSame(42, $this->sync->commit(static fn (): int => 42));
    }

    public function testAUsersChangeThatDoesNotTouchMembershipsDoesNotRestartAuthelia(): void
    {
        $this->completeSetup();
        $this->accessSync->sync();
        $this->applier->applied = [];

        $this->sync->commit(fn () => $this->accounts->setPassword('bob', 'a different passphrase'));

        self::assertSame([], $this->applier->applied, 'a new password changes users.yml only, which Authelia watches');
        self::assertTrue(password_verify('a different passphrase', Yaml::parseFile($this->paths->usersFile())['users']['bob']['password']));
    }

    public function testAFailedRenderCommitsNothing(): void
    {
        $this->sync->commit(fn () => $this->access->grant('bob', 'blog'));
        // A hand-edited row that cannot be rendered.
        $this->entityManager()->getConnection()->executeStatement("UPDATE fleet_user SET password_hash = 'not-a-hash' WHERE username = 'alice'");
        $this->entityManager()->clear();

        try {
            $this->sync->commit(fn () => $this->access->grant('bob', 'demo'));
            self::fail('Expected the render to refuse.');
        } catch (\InvalidArgumentException) {
        }

        $this->entityManager()->clear();
        self::assertSame(['blog'], $this->access->projectsOf('bob'), 'the membership change was rolled back with the render');
    }

    public function testUsersYmlIsNotWrittenWhenTheChangeIsRolledBack(): void
    {
        $this->sync->commit(fn () => $this->access->grant('bob', 'blog'));
        $before = (string) file_get_contents($this->paths->usersFile());

        try {
            $this->sync->commit(function (): void {
                $this->accounts->setPassword('bob', 'a different passphrase');

                throw new \RuntimeException('refused');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame($before, (string) file_get_contents($this->paths->usersFile()), 'the file never holds a rolled-back change');
    }

    public function testNothingIsInstalledWhenTheChangeFails(): void
    {
        $this->completeSetup();
        $this->accessSync->sync();
        $this->applier->applied = [];

        try {
            $this->sync->commit(function (): void {
                $this->access->grant('bob', 'blog');

                throw new \RuntimeException('refused');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->applier->applied);
    }

    public function testAFailedInstallAfterTheCommitCarriesTheResultAndKeepsTheChange(): void
    {
        $this->completeSetup();
        $this->applier->fails = new \RuntimeException('apply authelia failed (exit 1)');

        try {
            $this->sync->commit(fn (): bool => $this->access->grant('bob', 'blog'));
            self::fail('Expected the install failure.');
        } catch (AccessInstallFailed $e) {
            self::assertTrue($e->result, 'what the change returned');
            self::assertStringContainsString('the change was saved', strtolower($e->getMessage()));
            self::assertStringContainsString('apply authelia failed', $e->getMessage());
        }

        $this->entityManager()->clear();
        self::assertSame(['blog'], $this->access->projectsOf('bob'), 'a failed install is not a rollback');
        $this->applier->fails = null;
        self::assertTrue($this->sync->refreshAccess(), 'the install is retried');
    }

    public function testRefreshAccessIsTheCallForProjectChanges(): void
    {
        $this->completeSetup();
        $this->accessSync->sync();
        $projectService = new ProjectService(new ProjectRepository($this->entityManager()), new NamedPortRepository($this->entityManager()), new FakeInstanceGuard());

        $projectService->create(new ProjectInput(name: 'shop', git: 'https://git.example.com/acme/shop.git'));
        self::assertTrue($this->sync->refreshAccess(), 'a new project needs its rule (administrators open its sites)');
        $projectService->delete('shop', 'tester');
        self::assertTrue($this->sync->refreshAccess(), 'a deleted project loses its rule');
        self::assertFalse($this->sync->refreshAccess());
    }
}
