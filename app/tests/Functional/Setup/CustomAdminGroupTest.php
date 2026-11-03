<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Fleet\Auth\AutheliaUsersRenderer;
use App\Tests\Support\WizardTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * FLEET_ADMIN_GROUP is a documented setting: the group the wizard puts its administrator in, the group Authelia then
 * sends and the group that grants ROLE_ADMIN must be one and the same. Changing it must give a working administrator,
 * not one who signs in without an admin shell.
 */
final class CustomAdminGroupTest extends WizardTestCase
{
    private const string GROUP = 'my-admins';

    /** @var array{server: mixed, env: mixed, process: string|false, hadServer: bool, hadEnv: bool} */
    private array $previous;

    protected function setUp(): void
    {
        $this->previous = [
            'server' => $_SERVER['FLEET_ADMIN_GROUP'] ?? null,
            'env' => $_ENV['FLEET_ADMIN_GROUP'] ?? null,
            'process' => getenv('FLEET_ADMIN_GROUP'),
            'hadServer' => \array_key_exists('FLEET_ADMIN_GROUP', $_SERVER),
            'hadEnv' => \array_key_exists('FLEET_ADMIN_GROUP', $_ENV),
        ];
        $_SERVER['FLEET_ADMIN_GROUP'] = $_ENV['FLEET_ADMIN_GROUP'] = self::GROUP;
        putenv('FLEET_ADMIN_GROUP='.self::GROUP);

        self::ensureKernelShutdown();
        parent::setUp();
        $this->signIn();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['FLEET_ADMIN_GROUP'], $_ENV['FLEET_ADMIN_GROUP']);
        $this->previous['hadServer'] && $_SERVER['FLEET_ADMIN_GROUP'] = $this->previous['server'];
        $this->previous['hadEnv'] && $_ENV['FLEET_ADMIN_GROUP'] = $this->previous['env'];
        putenv(false === $this->previous['process'] ? 'FLEET_ADMIN_GROUP' : 'FLEET_ADMIN_GROUP='.$this->previous['process']);
        parent::tearDown();
    }

    public function testTheWizardsAdministratorGetsAWorkingAdminShellWithACustomGroup(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', [
            'admin_account[username]' => 'root',
            'admin_account[displayName]' => 'Root Admin',
            'admin_account[email]' => 'root@example.com',
            'admin_account[password][first]' => self::PASSWORD,
            'admin_account[password][second]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/setup/tls');

        // 1. What the wizard stored, and so what lands in Authelia's users.yml.
        $users = $this->users()->all();
        self::assertCount(1, $users);
        self::assertSame([self::GROUP], $users[0]->groups());
        $document = Yaml::parse(new AutheliaUsersRenderer()->render($users));
        self::assertSame([self::GROUP], $document['users']['root']['groups']);

        // 2. The wizard recognises that administrator again (progress and the audit name).
        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('root', $this->body());

        // 3. Authelia sends those groups back; the app turns them into ROLE_ADMIN. 404 = past the access control (an
        //    admin route that does not exist); 403 = signed in as a plain member.
        $this->completeSetupInDatabase();
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $request = static fn (string $groups): array => ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_REMOTE_USER' => 'root', 'HTTP_REMOTE_GROUPS' => $groups];

        $browser->request('GET', '/admin/x', server: $request(implode(',', $users[0]->groups())));
        self::assertResponseStatusCodeSame(404, 'the configured group is the admin group');

        $browser->request('GET', '/admin/x', server: $request('fleet-admins'));
        self::assertResponseStatusCodeSame(403, 'the default name is an ordinary group once the knob is changed');
    }
}
