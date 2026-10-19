<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Auth;

use App\Domain\Host\HostSettings;
use App\Fleet\Auth\AutheliaUsersFile;
use App\Fleet\Auth\ProjectAccessRules;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\System\AccessRuleSource;
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

    public function testRulesAreRenderedFromTheDatabase(): void
    {
        $em = $this->setUpFleetDatabase();
        self::assertInstanceOf(ProjectAccessRules::class, self::getContainer()->get('fleet.test_alias.access_rule_source'));
        self::assertInstanceOf(AccessRuleSource::class, self::getContainer()->get('fleet.test_alias.access_rule_source'));

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

        $rules = $this->rendered()['access_control']['rules'];

        self::assertCount(4, $rules);
        self::assertSame('^blog--[a-z0-9-]+\.fleet\.example\.com$', $rules[3]['domain_regex']);
        self::assertSame([['group:fleet-admins'], ['user:bob']], $rules[3]['subject']);
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
