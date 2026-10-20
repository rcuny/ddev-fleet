<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\PasswordPolicy;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin user pages through the real kernel: list, create, edit, password, enable / disable and delete, every
 * write reaching users.yml, and the protections (last administrator, yourself, CSRF, roles).
 *
 * The signed-in administrator is the dev stand-in `dev-admin`; it has a database row only in the tests that need one.
 */
final class UserPagesTest extends AdminTestCase
{
    private const string PASSWORD = 'correct horse battery staple';
    private const string SELF = 'dev-admin';

    private function makeUser(string $username, bool $admin = false, bool $enabled = true): void
    {
        $service = $this->container()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $service);
        $service->create($username, ucfirst($username), $username.'@example.com', self::PASSWORD, $admin);
        if (!$enabled) {
            $service->setEnabled($username, false);
        }
    }

    private function makeProject(string $name): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
    }

    private function grant(string $username, string $project): void
    {
        $service = $this->container()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $service);
        $service->grant($username, $project);
    }

    private function stored(string $username): ?User
    {
        return new UserRepository($this->em())->findByUsername($username);
    }

    private function user(string $username): User
    {
        return $this->stored($username) ?? self::fail(\sprintf('User "%s" is not stored.', $username));
    }

    private function userCount(): int
    {
        return \count(new UserRepository($this->em())->all());
    }

    /**
     * @return list<string>
     */
    private function projectsOf(string $username): array
    {
        $service = $this->container()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $service);
        $this->em();

        return $service->projectsOf($username);
    }

    /**
     * What users.yml says now, user => entry.
     *
     * @return array<string, array<string, mixed>>
     */
    private function usersFile(): array
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);
        if (!is_file($paths->usersFile())) {
            return [];
        }
        $parsed = Yaml::parseFile($paths->usersFile());
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['users']);

        return $parsed['users'];
    }

    /**
     * Submits the form behind $button with these fields replaced (null removes the field, which is how an unchecked box
     * looks), without the DomCrawler's own checks, so a test can also send what a browser never would.
     *
     * @param array<string, mixed> $fields
     */
    private function send(Crawler $crawler, string $button, string $form, array $fields, string $fetchSite = 'same-origin'): Crawler
    {
        $domForm = $crawler->selectButton($button)->form();
        $values = $domForm->getPhpValues();
        foreach ($fields as $name => $value) {
            if (null === $value) {
                unset($values[$form][$name]);
            } else {
                $values[$form][$name] = $value;
            }
        }

        return $this->client->request($domForm->getMethod(), $domForm->getUri(), $values, [], $this->browserServer($fetchSite));
    }

    /**
     * @return array<string, mixed>
     */
    private function newUser(string $username = 'alice', string $password = self::PASSWORD): array
    {
        return [
            'username' => $username,
            'displayName' => 'Alice Example',
            'email' => $username.'@example.com',
            'password' => $password,
            'admin' => null,
            'projects' => null,
        ];
    }

    // --- list

    public function testListShowsUsersWithRoleStateAndProjectCount(): void
    {
        $crawler = $this->open('/admin/users');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Users', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=users-empty]'));
        self::assertSame('/admin/users/new', $crawler->filter('main header a')->attr('href'));

        $this->makeProject('blog');
        $this->makeProject('shop');
        $this->makeUser('alice');
        $this->makeUser('bob', admin: true);
        $this->makeUser('carol', enabled: false);
        $this->grant('alice', 'blog');
        $this->grant('alice', 'shop');

        $crawler = $this->open('/admin/users');
        self::assertCount(0, $crawler->filter('[data-testid=users-empty]'));
        $rows = $crawler->filter('[data-testid=user-row]');
        self::assertCount(3, $rows);

        $alice = $rows->eq(0);
        self::assertSame('alice', $alice->attr('data-username'));
        self::assertSame('/admin/users/alice/edit', $alice->filter('a')->attr('href'));
        self::assertStringContainsString('Alice', $alice->text());
        self::assertStringContainsString('alice@example.com', $alice->text());
        self::assertSame('2 projects', trim($alice->filter('[data-testid=user-projects]')->text()));
        self::assertCount(0, $alice->filter('[data-testid=user-admin]'));
        self::assertCount(0, $alice->filter('[data-testid=user-disabled]'));

        $bob = $rows->eq(1);
        self::assertCount(1, $bob->filter('[data-testid=user-admin]'));
        self::assertSame('All projects', trim($bob->filter('[data-testid=user-projects]')->text()));

        $carol = $rows->eq(2);
        self::assertCount(1, $carol->filter('[data-testid=user-disabled]'));
        self::assertSame('0 projects', trim($carol->filter('[data-testid=user-projects]')->text()));
    }

    // --- create

    public function testCreateStoresTheUserWritesUsersYmlAndRedirectsToEdit(): void
    {
        $this->makeProject('blog');
        $this->makeProject('shop');

        $crawler = $this->open('/admin/users/new');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('New user', trim($crawler->filter('main h1')->text()));
        self::assertNotNull($crawler->filter('main form[data-testid=user-form]')->attr('novalidate'));
        self::assertSame('password', $crawler->filter('#user_create_password')->attr('type'));
        self::assertCount(2, $crawler->filter('input[type=checkbox][name="user_create[projects][]"]'));

        $this->send($crawler, 'Create user', 'user_create', ['username' => 'alice', 'displayName' => ' Alice Example ', 'email' => 'alice@example.com', 'password' => self::PASSWORD, 'admin' => null, 'projects' => ['blog']]);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        $user = $this->user('alice');
        self::assertSame('Alice Example', $user->displayName(), 'trimmed');
        self::assertTrue($user->isEnabled());
        self::assertSame([], $user->groups(), 'a member');
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash()));
        self::assertStringStartsWith('$argon2id$', $user->passwordHash());
        self::assertSame(['blog'], $this->projectsOf('alice'));

        $file = $this->usersFile();
        self::assertArrayHasKey('alice', $file);
        self::assertSame($user->passwordHash(), $file['alice']['password']);
        self::assertFalse($file['alice']['disabled']);

        $crawler = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('User "alice" created.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('Alice Example', $crawler->filter('#user_edit_displayName')->attr('value'));
    }

    public function testCreateAnAdministrator(): void
    {
        $crawler = $this->open('/admin/users/new');
        $this->send($crawler, 'Create user', 'user_create', ['admin' => '1'] + $this->newUser('root'));

        self::assertResponseRedirects('/admin/users/root/edit', 303);
        self::assertSame(['fleet-admins'], $this->user('root')->groups());
        self::assertSame(['fleet-admins'], $this->usersFile()['root']['groups']);
    }

    public function testCreateWithNoProjectsYetSaysSo(): void
    {
        $crawler = $this->open('/admin/users/new');

        self::assertCount(1, $crawler->filter('[data-testid=projects-none]'));
        self::assertCount(0, $crawler->filter('input[type=checkbox][name="user_create[projects][]"]'));
    }

    public function testWeakPasswordShowsThePolicyMessageAndStoresNothing(): void
    {
        $expected = implode(' ', new PasswordPolicy()->violations('short', 'alice'));
        self::assertNotSame('', $expected);

        $crawler = $this->open('/admin/users/new');
        $crawler = $this->send($crawler, 'Create user', 'user_create', $this->newUser('alice', 'short'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame($expected, trim($crawler->filter('#user_create_password_errors')->text()));
        self::assertSame('true', $crawler->filter('#user_create_password')->attr('aria-invalid'));
        self::assertNull($crawler->filter('#user_create_password')->attr('value'), 'the password is never sent back');
        self::assertSame('alice', $crawler->filter('#user_create_username')->attr('value'), 'the rest is kept');
        self::assertSame(0, $this->userCount());
        self::assertArrayNotHasKey('alice', $this->usersFile());
    }

    public function testBadFieldsShowTheirOwnErrors(): void
    {
        $crawler = $this->open('/admin/users/new');
        $crawler = $this->send($crawler, 'Create user', 'user_create', ['username' => 'Bad Name', 'displayName' => 'x', 'email' => 'not-an-email'] + $this->newUser());
        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', trim($crawler->filter('#user_create_username_errors')->text()));

        $crawler = $this->open('/admin/users/new');
        $crawler = $this->send($crawler, 'Create user', 'user_create', ['email' => 'not-an-email'] + $this->newUser());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The email address is not valid.', trim($crawler->filter('#user_create_email_errors')->text()));

        $crawler = $this->open('/admin/users/new');
        $crawler = $this->send($crawler, 'Create user', 'user_create', ['displayName' => '   '] + $this->newUser());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('display name', $crawler->filter('#user_create_displayName_errors')->text());
        self::assertSame(0, $this->userCount());
    }

    public function testDuplicateUsernameIsRefused(): void
    {
        $this->makeUser('alice');

        $crawler = $this->open('/admin/users/new');
        $crawler = $this->send($crawler, 'Create user', 'user_create', $this->newUser('alice'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A user with this username already exists.', trim($crawler->filter('#user_create_username_errors')->text()));
        self::assertSame(1, $this->userCount());
    }

    public function testAProjectThatDoesNotExistIsRefused(): void
    {
        $this->makeProject('blog');

        $crawler = $this->open('/admin/users/new');
        $crawler = $this->send($crawler, 'Create user', 'user_create', ['projects' => ['blog', 'ghost']] + $this->newUser());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('only projects that exist', $crawler->filter('main')->text());
        self::assertSame(0, $this->userCount());
    }

    // --- edit

    public function testEditShowsTheCurrentValuesAndSavesTheChanges(): void
    {
        $this->makeProject('blog');
        $this->makeProject('shop');
        $this->makeUser('alice');
        $this->grant('alice', 'blog');

        $crawler = $this->open('/admin/users/alice/edit');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Alice', trim($crawler->filter('main h1')->text()));
        self::assertSame('Alice', $crawler->filter('#user_edit_displayName')->attr('value'));
        self::assertSame('alice@example.com', $crawler->filter('#user_edit_email')->attr('value'));
        self::assertCount(0, $crawler->filter('#user_edit_admin[checked]'));
        self::assertCount(1, $crawler->filter('input[name="user_edit[projects][]"][value=blog][checked]'));
        self::assertCount(0, $crawler->filter('input[name="user_edit[projects][]"][value=shop][checked]'));
        self::assertCount(0, $crawler->filter('input[name="user_edit[projects][]"]:not([value=blog]):not([value=shop])'));

        $this->send($crawler, 'Save', 'user_edit', ['displayName' => 'Alice Liddell', 'email' => 'liddell@example.com', 'admin' => '1', 'projects' => ['shop']]);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        $user = $this->user('alice');
        self::assertSame('Alice Liddell', $user->displayName());
        self::assertSame('liddell@example.com', $user->email());
        self::assertSame(['fleet-admins'], $user->groups());
        self::assertSame(['shop'], $this->projectsOf('alice'));
        $entry = $this->usersFile()['alice'];
        self::assertSame('Alice Liddell', $entry['displayname']);
        self::assertSame(['fleet-admins', 'fleet-project-shop'], $entry['groups'], 'the membership is a project group in users.yml');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User saved.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertCount(1, $crawler->filter('#user_edit_admin[checked]'));
    }

    public function testUncheckingEveryProjectRemovesTheMemberships(): void
    {
        $this->makeProject('blog');
        $this->makeUser('alice');
        $this->grant('alice', 'blog');

        $crawler = $this->open('/admin/users/alice/edit');
        $this->send($crawler, 'Save', 'user_edit', ['projects' => null]);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertSame([], $this->projectsOf('alice'));
    }

    public function testEditWithABadEmailChangesNothing(): void
    {
        $this->makeProject('blog');
        $this->makeUser('alice');

        $crawler = $this->open('/admin/users/alice/edit');
        $crawler = $this->send($crawler, 'Save', 'user_edit', ['displayName' => 'Changed', 'email' => 'nope', 'projects' => ['blog']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The email address is not valid.', trim($crawler->filter('#user_edit_email_errors')->text()));
        $user = $this->user('alice');
        self::assertSame('Alice', $user->displayName());
        self::assertSame([], $this->projectsOf('alice'));
    }

    public function testEditingAMemberDoesNotTouchTheirPassword(): void
    {
        $this->makeUser('alice');
        $before = $this->user('alice')->passwordHash();

        $crawler = $this->open('/admin/users/alice/edit');
        $this->send($crawler, 'Save', 'user_edit', ['displayName' => 'Alicia']);

        self::assertSame($before, $this->user('alice')->passwordHash());
    }

    // --- password

    public function testAdminSetsANewPassword(): void
    {
        $this->makeUser('alice');
        $old = $this->user('alice')->passwordHash();

        $crawler = $this->open('/admin/users/alice/edit');
        $this->send($crawler, 'Set password', 'user_password', ['password' => 'another long passphrase']);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        $user = $this->user('alice');
        self::assertNotSame($old, $user->passwordHash());
        self::assertTrue(password_verify('another long passphrase', $user->passwordHash()));
        self::assertSame($user->passwordHash(), $this->usersFile()['alice']['password']);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The password of "alice" was changed.', $crawler->filter('[data-testid=flashes]')->text());
    }

    public function testAWeakNewPasswordKeepsTheOldOne(): void
    {
        $this->makeUser('alice');
        $old = $this->user('alice')->passwordHash();
        $expected = implode(' ', new PasswordPolicy()->violations('short', 'alice'));

        $crawler = $this->open('/admin/users/alice/edit');
        $crawler = $this->send($crawler, 'Set password', 'user_password', ['password' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($expected, trim($crawler->filter('#user_password_password_errors')->text()));
        self::assertSame('Alice', $crawler->filter('main h1')->text());
        self::assertSame($old, $this->user('alice')->passwordHash());
    }

    // --- enable / disable

    public function testDisableAndEnable(): void
    {
        $this->makeUser('alice');

        $crawler = $this->open('/admin/users/alice/edit');
        self::assertSame('/admin/users/alice/disable', $crawler->filter('[data-testid=user-status] form')->attr('action'));
        $this->post('/admin/users/alice/disable', []);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertFalse($this->user('alice')->isEnabled());
        self::assertTrue($this->usersFile()['alice']['disabled']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User "alice" disabled.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('/admin/users/alice/enable', $crawler->filter('[data-testid=user-status] form')->attr('action'));

        $this->post('/admin/users/alice/enable', []);
        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertTrue($this->user('alice')->isEnabled());
        self::assertFalse($this->usersFile()['alice']['disabled']);
    }

    // --- delete

    public function testDeleteRemovesTheUserAndItsAccess(): void
    {
        $this->makeProject('blog');
        $this->makeUser('alice');
        $this->makeUser('bob');
        $this->grant('alice', 'blog');
        $this->grant('bob', 'blog');

        $crawler = $this->open('/admin/users/alice/edit');
        self::assertCount(1, $crawler->filter('form[action="/admin/users/alice/delete"]'));
        $this->post('/admin/users/alice/delete', []);

        self::assertResponseRedirects('/admin/users', 303);
        self::assertNull($this->stored('alice'));
        self::assertNotNull($this->stored('bob'));
        self::assertArrayNotHasKey('alice', $this->usersFile());
        self::assertArrayHasKey('bob', $this->usersFile());
        $service = $this->container()->get(ProjectAccessService::class);
        self::assertInstanceOf(ProjectAccessService::class, $service);
        self::assertSame(['bob'], $service->membersOf('blog'), 'the access rows went with the user');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User "alice" deleted.', $crawler->filter('[data-testid=flashes]')->text());

        $this->post('/admin/users/alice/delete', []);
        self::assertResponseStatusCodeSame(404);
    }

    // --- the last administrator

    public function testTheLastEnabledAdministratorCannotBeDeletedDisabledOrDemoted(): void
    {
        $this->makeUser('carol', admin: true);
        $this->makeUser('dave', enabled: false);
        $file = $this->usersFile();

        $this->post('/admin/users/carol/delete', []);
        self::assertResponseRedirects('/admin/users/carol/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The user was not deleted.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertStringContainsString('last enabled administrator', $crawler->filter('[data-testid=flashes]')->text());
        self::assertNotNull($this->stored('carol'));

        $this->post('/admin/users/carol/disable', []);
        self::assertResponseRedirects('/admin/users/carol/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('last enabled administrator', $crawler->filter('[data-testid=flashes]')->text());
        self::assertTrue($this->user('carol')->isEnabled());

        $crawler = $this->open('/admin/users/carol/edit');
        $crawler = $this->send($crawler, 'Save', 'user_edit', ['admin' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('last enabled administrator', $crawler->filter('main [role=alert]')->text());
        self::assertSame(['fleet-admins'], $this->user('carol')->groups());
        self::assertSame($file['carol'], $this->usersFile()['carol'], 'users.yml was not touched');
    }

    public function testADisabledAdministratorDoesNotCountAndAnotherAdminCanBeDeleted(): void
    {
        $this->makeUser('carol', admin: true);
        $this->makeUser('erin', admin: true);

        $this->post('/admin/users/erin/delete', []);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertNull($this->stored('erin'));

        // carol is now the last one again.
        $this->post('/admin/users/carol/delete', []);
        self::assertNotNull($this->stored('carol'));
    }

    // --- yourself

    public function testYouCannotDeleteDisableOrDemoteYourself(): void
    {
        $this->makeUser(self::SELF, admin: true);
        $this->makeUser('carol', admin: true);

        $crawler = $this->open('/admin/users/'.self::SELF.'/edit');
        self::assertCount(0, $crawler->filter('[data-testid=danger-zone]'), 'no delete offered for yourself');
        self::assertCount(0, $crawler->filter('[data-testid=user-status] form'), 'no disable offered for yourself');
        self::assertCount(1, $crawler->filter('#user_edit_admin[disabled]'));

        $this->post('/admin/users/'.self::SELF.'/delete', []);
        self::assertResponseRedirects('/admin/users/'.self::SELF.'/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The user was not deleted.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertNotNull($this->stored(self::SELF));

        $this->post('/admin/users/'.self::SELF.'/disable', []);
        self::assertTrue($this->user(self::SELF)->isEnabled());

        // A forged request that unchecks the (disabled) box is ignored: the role stays.
        $crawler = $this->open('/admin/users/'.self::SELF.'/edit');
        $this->send($crawler, 'Save', 'user_edit', ['admin' => null, 'displayName' => 'Me']);
        $user = $this->user(self::SELF);
        self::assertSame(['fleet-admins'], $user->groups());
        self::assertSame('Me', $user->displayName());
    }

    // --- CSRF and request shape

    public function testPostsFromAnotherSiteChangeNothing(): void
    {
        $this->makeUser('alice');
        $this->makeUser('bob');
        $old = $this->user('alice')->passwordHash();

        foreach (['delete', 'disable'] as $action) {
            $this->post('/admin/users/alice/'.$action, [], fetchSite: 'cross-site');
            self::assertResponseStatusCodeSame(403, $action);
        }
        $this->client->request('POST', self::BASE.'/admin/users/alice/delete', [], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403, 'no token');
        $this->client->request('POST', self::BASE.'/admin/users/alice/delete', ['_token' => 'x'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403, 'short token');
        $this->post('/admin/users/alice/enable', [], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403, 'enable');

        $crawler = $this->open('/admin/users/alice/edit');
        $this->send($crawler, 'Set password', 'user_password', ['password' => 'another long passphrase'], 'cross-site');
        self::assertResponseStatusCodeSame(422);
        $this->send($crawler, 'Save', 'user_edit', ['displayName' => 'Evil'], 'cross-site');
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->open('/admin/users/new');
        $this->send($crawler, 'Create user', 'user_create', $this->newUser('mallory'), 'cross-site');
        self::assertResponseStatusCodeSame(422);

        $user = $this->user('alice');
        self::assertSame($old, $user->passwordHash());
        self::assertSame('Alice', $user->displayName());
        self::assertTrue($user->isEnabled());
        self::assertNull($this->stored('mallory'));
        self::assertSame(2, $this->userCount());
    }

    public function testActionsArePostOnly(): void
    {
        $this->makeUser('alice');

        foreach (['delete', 'disable', 'enable', 'password'] as $action) {
            $this->open('/admin/users/alice/'.$action);
            self::assertResponseStatusCodeSame(404, $action);
        }
        self::assertNotNull($this->stored('alice'));
    }

    #[DataProvider('oddPaths')]
    public function testUnknownOrOddUsernamesAre404(string $path): void
    {
        $this->makeUser('alice');

        $this->open($path);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oddPaths(): iterable
    {
        yield 'unknown' => ['/admin/users/nobody/edit'];
        yield 'upper case' => ['/admin/users/Alice/edit'];
        yield 'too long' => ['/admin/users/'.str_repeat('a', 65).'/edit'];
        yield 'dots' => ['/admin/users/../edit'];
        yield 'no suffix' => ['/admin/users/alice'];
    }

    public function testAUserCalledNewDoesNotShadowTheCreatePage(): void
    {
        $this->makeUser('new');

        $crawler = $this->open('/admin/users/new');
        self::assertSame('New user', trim($crawler->filter('main h1')->text()));

        $crawler = $this->open('/admin/users/new/edit');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('New', trim($crawler->filter('main h1')->text()));
    }

    // --- roles

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'list' => ['GET', '/admin/users'];
        yield 'new' => ['GET', '/admin/users/new'];
        yield 'create' => ['POST', '/admin/users/new'];
        yield 'edit' => ['GET', '/admin/users/alice/edit'];
        yield 'save' => ['POST', '/admin/users/alice/edit'];
        yield 'password' => ['POST', '/admin/users/alice/password'];
        yield 'enable' => ['POST', '/admin/users/alice/enable'];
        yield 'disable' => ['POST', '/admin/users/alice/disable'];
        yield 'delete' => ['POST', '/admin/users/alice/delete'];
    }

    #[DataProvider('routes')]
    public function testMemberGets403OnEveryRoute(string $method, string $path): void
    {
        $this->makeUser('alice');
        $this->as('member');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->stored('alice'));
        self::assertSame(1, $this->userCount());
    }

    #[DataProvider('routes')]
    public function testAnonymousGets401OnEveryRoute(string $method, string $path): void
    {
        $this->makeUser('alice');
        $this->as('none');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        self::assertResponseStatusCodeSame(401);
        self::assertSame(1, $this->userCount());
    }

    #[DataProvider('routes')]
    public function testAdminReachesEveryRoute(string $method, string $path): void
    {
        $this->makeUser('alice');

        $this->client->request($method, self::BASE.$path, 'POST' === $method ? ['_token' => 'csrf-token'] : [], [], $this->browserServer());

        // GETs render, a bare POST to a form is a 422 (nothing valid submitted), the one-click actions redirect.
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 303, 422], $method.' '.$path);
    }

    public function testTheUsersEntryIsInTheNavigation(): void
    {
        $crawler = $this->open('/admin/users');

        self::assertGreaterThan(0, $crawler->filter('a[href="/admin/users"]')->count());
        self::assertCount(0, $crawler->filter('[data-testid=placeholder]'));
    }
}
