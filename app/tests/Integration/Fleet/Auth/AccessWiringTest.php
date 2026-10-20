<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\Host\HostSettings;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\System\AutheliaConfigRenderer;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The container's own AutheliaConfigRenderer: access rules come from the database, and the self-service reset follows
 * the FLEET_AUTHELIA_SMTP_* environment (unset = disabled).
 */
final class AccessWiringTest extends KernelTestCase
{
    use FleetDatabase;

    private const array VARIABLES = ['FLEET_AUTHELIA_SMTP_ADDRESS', 'FLEET_AUTHELIA_SMTP_SENDER', 'FLEET_AUTHELIA_SMTP_USERNAME'];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function rendered(): array
    {
        $renderer = self::getContainer()->get('fleet.test_alias.authelia_config_renderer');
        self::assertInstanceOf(AutheliaConfigRenderer::class, $renderer);
        $parsed = Yaml::parse($renderer->render(new HostSettings('fleet.example.com')));
        self::assertIsArray($parsed);

        return $parsed;
    }

    public function testMembershipsAreRenderedAsProjectGroupsAndNotAsRules(): void
    {
        $em = $this->setUpFleetDatabase();

        $accounts = self::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);
        $accounts->upsertAdmin('bob', 'Bob', 'bob@example.com', 'correct horse battery staple');
        $projects = self::getContainer()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput(name: 'blog', git: 'https://git.example.com/acme/blog.git'));
        $access = self::getContainer()->get('fleet.test_alias.project_access_service');
        self::assertInstanceOf(ProjectAccessService::class, $access);
        $access->grant('bob', 'blog');
        $em->clear();

        // Authelia's configuration has one static rule for the instance hosts, whatever the memberships are.
        $rules = $this->rendered()['access_control']['rules'];
        self::assertCount(3, $rules);
        self::assertSame(['domain' => '*.fleet.example.com', 'policy' => 'one_factor'], $rules[2]);

        // The membership is in users.yml, which Authelia reloads live.
        $users = self::getContainer()->get('fleet.test_alias.authelia_users_file');
        self::assertInstanceOf(AutheliaUsersFile::class, $users);
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $users->sync($repository);
        $paths = self::getContainer()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        self::assertSame(['fleet-admins', 'fleet-project-blog'], Yaml::parseFile($paths->usersFile())['users']['bob']['groups']);
    }

    public function testTheSelfServiceResetIsDisabledUntilAnSmtpServerIsConfigured(): void
    {
        $this->setUpFleetDatabase();

        $parsed = $this->rendered();

        self::assertTrue($parsed['authentication_backend']['password_reset']['disable']);
        self::assertArrayHasKey('filesystem', $parsed['notifier']);
        $users = self::getContainer()->get('fleet.test_alias.authelia_users_file');
        self::assertInstanceOf(AutheliaUsersFile::class, $users);
    }

    public function testTheEnvironmentEnablesTheSelfServiceReset(): void
    {
        putenv('FLEET_AUTHELIA_SMTP_ADDRESS=submission://mail.example.com:587');
        putenv('FLEET_AUTHELIA_SMTP_SENDER=fleet@example.com');
        $this->setUpFleetDatabase();

        $parsed = $this->rendered();

        self::assertFalse($parsed['authentication_backend']['password_reset']['disable']);
        self::assertSame(['smtp' => ['address' => 'submission://mail.example.com:587', 'sender' => 'fleet@example.com']], $parsed['notifier']);
    }
}
