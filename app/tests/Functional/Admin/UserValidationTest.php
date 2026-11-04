<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Fleet\Auth\ProjectAccessService;
use App\Fleet\Auth\UserService;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every message the user pages show, field by field, as tables of inputs and expected messages, plus the states the
 * Playwright specs used to walk through a browser (FLE-161): the refused forms (422, one message under its field,
 * aria-invalid and aria-describedby wired, nothing stored, the password never sent back), inclusive limits, trimming,
 * the site-only kind, memberships, enable / disable, the last-administrator and self protections with their exact
 * messages. The pages' wiring is in UserPagesTest; the browser keeps only what needs one (dialogs, toggles, overflow).
 */
final class UserValidationTest extends AdminTestCase
{
    private const string PASSWORD = 'correct horse battery staple';
    private const string USERNAME_MESSAGE = 'The username must be 1-64 characters: lowercase letters, digits, dot, underscore or dash, starting with a letter or digit.';
    private const string DISPLAY_NAME_MESSAGE = 'The display name must be 1-255 characters without control characters.';
    private const string EMAIL_MESSAGE = 'The email address is not valid.';
    private const string SHORT_PASSWORD = 'The password must be at least 12 characters long.';
    private const string SITE_ONLY_SHORT_PASSWORD = 'The password must be at least 4 characters long.';
    private const string LONG_PASSWORD = 'The password must be at most 128 characters long.';
    private const string SITE_ONLY_ADMIN = 'A site-only user cannot be an administrator.';
    private const string SELF = 'dev-admin';

    private function makeUser(string $username, bool $admin = false, bool $enabled = true, string $displayName = ''): void
    {
        $service = $this->container()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $service);
        $service->create($username, '' === $displayName ? ucfirst($username) : $displayName, $username.'@example.com', self::PASSWORD, $admin);
        if (!$enabled) {
            $service->setEnabled($username, false);
        }
    }

    private function makeSiteOnly(string $username, string $password = 'abcd'): void
    {
        $service = $this->container()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $service);
        $service->create($username, ucfirst($username), '', $password, false, true);
    }

    private function makeProject(string $name): void
    {
        $service = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $service);
        $service->create(new ProjectInput($name, 'https://git.example.com/acme/'.$name.'.git'));
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
     * @param array<string, mixed> $fields
     */
    private function send(Crawler $crawler, string $button, string $form, array $fields): Crawler
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

        return $this->client->request($domForm->getMethod(), $domForm->getUri(), $values, [], $this->browserServer());
    }

    /**
     * @param array<string, mixed> $override
     */
    private function create(array $override = []): Crawler
    {
        $crawler = $this->open('/admin/users/new');

        return $this->send($crawler, 'Create user', 'user_create', $override + ['username' => 'pwabc12', 'displayName' => 'Spec Person', 'email' => 'pwabc12@example.com', 'password' => self::PASSWORD, 'admin' => null, 'projects' => null]);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function save(string $username, array $override): Crawler
    {
        return $this->send($this->open('/admin/users/'.$username.'/edit'), 'Save', 'user_edit', $override);
    }

    private function setPassword(string $username, string $password): Crawler
    {
        return $this->send($this->open('/admin/users/'.$username.'/edit'), 'Set password', 'user_password', ['password' => $password]);
    }

    /**
     * Exactly one message list on the form, under $field, holding exactly $message, the field wired to it.
     */
    private function assertOnlyError(Crawler $crawler, string $form, string $field, string $message): void
    {
        $id = $form.'_'.$field;
        self::assertSame($message, trim($crawler->filter('#'.$id.'_errors')->text()), $field);
        self::assertCount(1, $crawler->filter('[id^='.$form.'_][id$=_errors]'), 'no other field has a message');
        self::assertCount(0, $crawler->filter('[data-testid=user-form] [role=alert]'), 'no form-level alert');
        self::assertSame('true', $crawler->filter('#'.$id)->attr('aria-invalid'), $field.' is marked invalid');
        self::assertStringContainsString($id.'_errors', (string) $crawler->filter('#'.$id)->attr('aria-describedby'), $field.' points at its message');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedOnCreate(): iterable
    {
        foreach (['empty' => '', 'upper case' => 'Upper', 'a space' => 'has space', 'a leading dash' => '-dash', 'a leading dot' => '.dot', 'an at sign' => 'at@sign', '65 characters' => str_repeat('a', 65)] as $case => $bad) {
            yield 'username: '.$case => ['username', $bad, self::USERNAME_MESSAGE];
        }
        yield 'username: taken' => ['username', 'taken', 'A user with this username already exists.'];
        foreach (['empty' => '', 'blank' => '   ', '256 characters' => str_repeat('x', 256), 'a control character' => "be\x07ll", 'an invisible format character' => "zero\u{200B}width"] as $case => $bad) {
            yield 'displayName: '.$case => ['displayName', $bad, self::DISPLAY_NAME_MESSAGE];
        }
        foreach (['empty' => '', 'no at sign' => 'not-an-email', 'no dot in the domain' => 'name@host', 'a space inside' => 'na me@example.com', '255 characters' => str_repeat('a', 244).'@example.com'] as $case => $bad) {
            yield 'email: '.$case => ['email', $bad, self::EMAIL_MESSAGE];
        }
        yield 'password: empty' => ['password', '', self::SHORT_PASSWORD];
        yield 'password: 11 characters' => ['password', 'elevenchars', self::SHORT_PASSWORD];
        yield 'password: 129 characters' => ['password', str_repeat('x', 129), self::LONG_PASSWORD];
        yield 'password: NUL' => ['password', "nul\x00".str_repeat('x', 12), 'The password must not contain NUL characters.'];
        yield 'password: the username in another case' => ['password', 'PWABC12', self::SHORT_PASSWORD.' The password must not be the username.'];
    }

    #[DataProvider('refusedOnCreate')]
    public function testTheNewUserFormRefusesOneRuleAtATime(string $field, string $bad, string $message): void
    {
        $this->makeUser('taken');
        $before = $this->userCount();

        $crawler = $this->create([$field => $bad]);

        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_create', $field, $message);
        self::assertSame($before, $this->userCount(), 'nothing is created');
        self::assertNull($this->stored('pwabc12'));
        if ('password' === $field) {
            self::assertNull($crawler->filter('#user_create_password')->attr('value'), 'a password is never sent back');
        }
        if ('username' !== $field) {
            self::assertSame('pwabc12', $crawler->filter('#user_create_username')->attr('value'), 'the valid values stay');
        }
        if ('displayName' !== $field) {
            self::assertSame('Spec Person', $crawler->filter('#user_create_displayName')->attr('value'));
        }
    }

    public function testTheFormsCarryTheLengthLimitsOfTheRules(): void
    {
        $crawler = $this->open('/admin/users/new');
        self::assertSame('64', $crawler->filter('#user_create_username')->attr('maxlength'));
        self::assertSame('255', $crawler->filter('#user_create_displayName')->attr('maxlength'));
        self::assertSame('254', $crawler->filter('#user_create_email')->attr('maxlength'));
        self::assertSame('128', $crawler->filter('#user_create_password')->attr('maxlength'));

        $this->makeUser('alice');
        $crawler = $this->open('/admin/users/alice/edit');
        self::assertCount(0, $crawler->filter('#user_edit_username'), 'the username is fixed');
        self::assertSame('255', $crawler->filter('#user_edit_displayName')->attr('maxlength'));
        self::assertSame('254', $crawler->filter('#user_edit_email')->attr('maxlength'));
        self::assertSame('128', $crawler->filter('#user_password_password')->attr('maxlength'));
        self::assertSame('new-password', $crawler->filter('#user_password_password')->attr('autocomplete'));
    }

    public function testTheLimitsAreInclusive(): void
    {
        $long = str_pad('lim.', 64, 'x');
        $this->create(['username' => $long, 'displayName' => str_repeat('D', 255), 'password' => str_repeat('p', 128), 'email' => str_repeat('e', 64).'@'.str_repeat('d', 60).'.example.com']);
        self::assertResponseRedirects('/admin/users/'.$long.'/edit', 303);
        self::assertSame(255, mb_strlen($this->user($long)->displayName()));
        self::assertTrue(password_verify(str_repeat('p', 128), $this->user($long)->passwordHash()));
        self::assertSame(str_repeat('D', 255), $this->client->followRedirect()->filter('#user_edit_displayName')->attr('value'));

        $this->create(['username' => 'lim-other', 'password' => 'twelve chars']);
        self::assertResponseRedirects('/admin/users/lim-other/edit', 303);

        // Dots, underscores and dashes in the middle of a username work through the edit page and the delete.
        $this->create(['username' => 'pw1.a_b-c']);
        self::assertResponseRedirects('/admin/users/pw1.a_b-c/edit', 303);
        $this->open('/admin/users/pw1.a_b-c/edit');
        self::assertResponseStatusCodeSame(200);
        $this->post('/admin/users/pw1.a_b-c/delete', []);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertNull($this->stored('pw1.a_b-c'));
    }

    public function testSurroundingSpacesAreTrimmedAndAPasswordCountsCharactersNotBytes(): void
    {
        $password = 'パスワードパスワードパスワード';
        self::assertSame(12, mb_strlen(mb_substr($password, 0, 12)));

        $this->create(['username' => 'spaced', 'displayName' => '   Spaced Person  ', 'email' => '  spaced@example.com ', 'password' => $password]);

        self::assertResponseRedirects('/admin/users/spaced/edit', 303);
        $user = $this->user('spaced');
        self::assertSame('Spaced Person', $user->displayName());
        self::assertSame('spaced@example.com', $user->email());
        self::assertTrue(password_verify($password, $user->passwordHash()));
        $crawler = $this->client->followRedirect();
        self::assertSame('Spaced Person', trim($crawler->filter('main h1')->text()));
        self::assertSame('Spaced Person', $crawler->filter('#user_edit_displayName')->attr('value'));
        self::assertSame('spaced@example.com', $crawler->filter('#user_edit_email')->attr('value'));
    }

    public function testASiteOnlyUserIsRefusedWithTheSiteOnlyMinimumAndKeepsItsShapeAndProjects(): void
    {
        $this->makeProject('blog');
        $shape = ['username' => 'shield', 'displayName' => 'Client shield', 'email' => '', 'siteOnly' => '1', 'projects' => ['blog']];

        $crawler = $this->create($shape + ['password' => 'abc']);
        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_create', 'password', self::SITE_ONLY_SHORT_PASSWORD);
        self::assertCount(1, $crawler->filter('#user_create_siteOnly[checked]'), 'the form keeps its site-only shape');
        self::assertCount(1, $crawler->filter('input[name="user_create[projects][]"][value=blog][checked]'), 'and the project');
        self::assertNull($this->stored('shield'));

        $crawler = $this->create(['displayName' => ''] + $shape + ['password' => 'abcd']);
        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_create', 'displayName', self::DISPLAY_NAME_MESSAGE);

        // A password equal to the username is fine for a site-only user; a typed email is ignored.
        $this->create(['email' => 'ignored@example.com', 'password' => 'shield'] + $shape);
        self::assertResponseRedirects('/admin/users/shield/edit', 303);
        $user = $this->user('shield');
        self::assertTrue($user->isSiteOnly());
        self::assertSame('', $user->email());
        $crawler = $this->client->followRedirect();
        self::assertSame('Client shield', trim($crawler->filter('main h1')->text()));

        $row = $this->open('/admin/users')->filter('[data-username=shield]');
        self::assertSame('Site access only', trim($row->filter('[data-testid=user-site-only]')->text()));
        self::assertSame('1 project', trim($row->filter('[data-testid=user-projects]')->text()));
        self::assertStringNotContainsString('@', $row->text());
    }

    public function testTheNewUserPageOffersCancelBackToTheList(): void
    {
        $crawler = $this->open('/admin/users/new');

        self::assertSame('/admin/users', $crawler->selectLink('Cancel')->attr('href'));
        self::assertSame('/admin/users/new', $this->open('/admin/users')->selectLink('New user')->attr('href'));
        self::assertSame(0, $this->userCount());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedOnEdit(): iterable
    {
        yield 'displayName: empty' => ['displayName', '', self::DISPLAY_NAME_MESSAGE];
        yield 'displayName: 256 characters' => ['displayName', str_repeat('x', 256), self::DISPLAY_NAME_MESSAGE];
        yield 'displayName: a control character' => ['displayName', "be\x07ll", self::DISPLAY_NAME_MESSAGE];
        yield 'email: empty' => ['email', '', self::EMAIL_MESSAGE];
        yield 'email: no at sign' => ['email', 'not-an-email', self::EMAIL_MESSAGE];
        yield 'email: no dot in the domain' => ['email', 'name@host', self::EMAIL_MESSAGE];
        yield 'email: 255 characters' => ['email', str_repeat('a', 244).'@example.com', self::EMAIL_MESSAGE];
    }

    #[DataProvider('refusedOnEdit')]
    public function testTheEditFormRefusesOneRuleAtATimeAndChangesNothing(string $field, string $bad, string $message): void
    {
        $this->makeUser('alice', displayName: 'Before Name');
        $hash = $this->user('alice')->passwordHash();

        $crawler = $this->save('alice', [$field => $bad]);

        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_edit', $field, $message);
        $user = $this->user('alice');
        self::assertSame('Before Name', $user->displayName());
        self::assertSame('alice@example.com', $user->email());
        self::assertSame($hash, $user->passwordHash());
    }

    public function testTheProfileSavesTrimmedAndTheListAndPageFollow(): void
    {
        $this->makeUser('alice', displayName: 'Before Name');

        $this->save('alice', ['displayName' => '  After Name ', 'email' => 'new.alice@example.org']);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User saved.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('After Name', trim($crawler->filter('main h1')->text()));
        self::assertSame('After Name', $crawler->filter('#user_edit_displayName')->attr('value'));
        self::assertSame('new.alice@example.org', $crawler->filter('#user_edit_email')->attr('value'));
        self::assertSame('/admin/users', $crawler->selectLink('Back to users')->attr('href'));
        $row = $this->open('/admin/users')->filter('[data-username=alice]');
        self::assertStringContainsString('alice · new.alice@example.org', preg_replace('/\s+/', ' ', $row->text()) ?? '');
        self::assertStringContainsString('After Name', $row->text());
    }

    public function testMembershipsAreAddedRemovedAndClearedAndTheListCountFollows(): void
    {
        $this->makeProject('blog');
        $this->makeProject('shop');
        $this->makeUser('alice');
        $count = fn (): string => trim($this->open('/admin/users')->filter('[data-username=alice] [data-testid=user-projects]')->text());
        $checked = fn (): array => $this->open('/admin/users/alice/edit')->filter('input[name="user_edit[projects][]"][checked]')->each(static fn (Crawler $c): string => (string) $c->attr('value'));

        self::assertSame([], $checked());
        self::assertSame('0 projects', $count());

        $this->save('alice', ['projects' => ['blog', 'shop']]);
        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertSame(['blog', 'shop'], $checked());
        self::assertSame('2 projects', $count());

        $this->save('alice', ['projects' => ['shop']]);
        self::assertSame(['shop'], $checked());
        self::assertSame('1 project', $count());

        $this->save('alice', ['projects' => null]);
        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertSame([], $checked());
        self::assertSame('0 projects', $count());
    }

    public function testARegularUserBecomesSiteOnlyAndBackAndNeedsAValidEmailToReturn(): void
    {
        $this->makeUser('bob', displayName: 'Regular');
        $crawler = $this->open('/admin/users/bob/edit');
        self::assertCount(0, $crawler->filter('[data-testid=user-site-only]'));
        self::assertCount(1, $crawler->filter('[data-testid=site-only-warning][hidden]'));
        self::assertStringContainsString('Replaces the current password. The person can also reset it', $crawler->filter('[data-testid=password-card]')->text());

        $this->save('bob', ['siteOnly' => '1', 'email' => '']);
        self::assertResponseRedirects('/admin/users/bob/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User saved.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertCount(1, $crawler->filter('#user_edit_siteOnly[checked]'));
        self::assertCount(1, $crawler->filter('[data-testid=user-status] [data-testid=user-site-only]'));
        self::assertStringContainsString('bob · site access only', preg_replace('/\s+/', ' ', $crawler->filter('main header')->text()) ?? '');
        self::assertStringContainsString('at least 4 characters', $crawler->filter('[data-testid=password-card]')->text());

        // Back to a regular user without an email: refused under the email, and the user stays site-only.
        $crawler = $this->save('bob', ['siteOnly' => null, 'email' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_edit', 'email', self::EMAIL_MESSAGE);
        self::assertTrue($this->user('bob')->isSiteOnly());

        $this->save('bob', ['siteOnly' => null, 'email' => 'bob@example.com']);
        self::assertResponseRedirects('/admin/users/bob/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid=user-site-only]'));
        self::assertStringContainsString('bob · bob@example.com', preg_replace('/\s+/', ' ', $crawler->filter('main header')->text()) ?? '');
    }

    public function testAnAdministratorCannotBeMadeSiteOnlyAndTheMessageSitsUnderTheBox(): void
    {
        $this->makeUser('keeper', admin: true);
        $this->makeUser('carol', admin: true, displayName: 'Second Admin');

        $crawler = $this->open('/admin/users/carol/edit');
        self::assertCount(1, $crawler->filter('#user_edit_admin[checked]'));
        self::assertStringContainsString('Admin', $crawler->filter('[data-testid=user-status]')->text());
        $row = $this->open('/admin/users')->filter('[data-username=carol]');
        self::assertSame('Admin', trim($row->filter('[data-testid=user-admin]')->text()));
        self::assertSame('All projects', trim($row->filter('[data-testid=user-projects]')->text()));

        $crawler = $this->save('carol', ['siteOnly' => '1']);
        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_edit', 'siteOnly', self::SITE_ONLY_ADMIN);
        self::assertFalse($this->user('carol')->isSiteOnly());

        // Demote (another administrator exists), then promote again.
        $this->save('carol', ['admin' => null]);
        self::assertResponseRedirects('/admin/users/carol/edit', 303);
        self::assertSame([], $this->user('carol')->groups());
        $row = $this->open('/admin/users')->filter('[data-username=carol]');
        self::assertCount(0, $row->filter('[data-testid=user-admin]'));
        self::assertSame('0 projects', trim($row->filter('[data-testid=user-projects]')->text()));
        $this->save('carol', ['admin' => '1']);
        self::assertResponseRedirects('/admin/users/carol/edit', 303);
        self::assertSame(['fleet-admins'], $this->user('carol')->groups());
    }

    public function testActionsOnAMissingUserAre404AndAWrongTokenIs403(): void
    {
        $this->makeUser('alice');

        $this->open('/admin/users/ghost/edit');
        self::assertResponseStatusCodeSame(404);
        foreach (['password', 'enable', 'disable', 'delete'] as $action) {
            $this->client->request('POST', self::BASE.'/admin/users/ghost/'.$action, ['_token' => 'x'], [], $this->browserServer());
            self::assertResponseStatusCodeSame(404, $action.' of a missing user');
        }
        foreach (['enable', 'disable', 'delete'] as $action) {
            $this->client->request('POST', self::BASE.'/admin/users/alice/'.$action, ['_token' => 'x'], [], $this->browserServer());
            self::assertResponseStatusCodeSame(403, $action.' with a wrong token');
        }
        $crawler = $this->open('/admin/users/alice/edit');
        self::assertStringContainsString('Active', $crawler->filter('[data-testid=user-status]')->text());
        self::assertTrue($this->user('alice')->isEnabled());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedPasswords(): iterable
    {
        yield 'empty' => ['', self::SHORT_PASSWORD];
        yield '11 characters' => ['elevenchars', self::SHORT_PASSWORD];
        yield '129 characters' => [str_repeat('x', 129), self::LONG_PASSWORD];
        yield 'the username in another case' => ['PWABC12', self::SHORT_PASSWORD.' The password must not be the username.'];
        yield 'NUL' => ["nul\x00".str_repeat('x', 12), 'The password must not contain NUL characters.'];
    }

    #[DataProvider('refusedPasswords')]
    public function testASetPasswordIsRefusedWithItsMessageAndTheFieldIsEmptied(string $bad, string $message): void
    {
        $this->makeUser('pwabc12', displayName: 'Spec Person');
        $hash = $this->user('pwabc12')->passwordHash();

        $crawler = $this->setPassword('pwabc12', $bad);

        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_password', 'password', $message);
        self::assertNull($crawler->filter('#user_password_password')->attr('value'));
        self::assertSame('Spec Person', $crawler->filter('#user_edit_displayName')->attr('value'), 'the profile form next to it is intact');
        self::assertCount(0, $crawler->filter('#user_edit_displayName_errors'));
        self::assertSame($hash, $this->user('pwabc12')->passwordHash());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedPasswords(): iterable
    {
        yield '12 characters of several bytes' => [str_repeat('é', 12)];
        yield '128 characters' => [str_repeat('p', 128)];
        yield 'a sentence' => [self::PASSWORD];
    }

    #[DataProvider('acceptedPasswords')]
    public function testAGoodPasswordIsSetNamesTheAutheliaBanAndIsNeverEchoed(string $good): void
    {
        $this->makeUser('alice');

        $this->setPassword('alice', $good);

        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertTrue(password_verify($good, $this->user('alice')->passwordHash()));
        $crawler = $this->client->followRedirect();
        $flashes = $crawler->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('The password of "alice" was changed.', $flashes);
        self::assertStringContainsString('Authelia may still block "alice" for up to', $flashes);
        self::assertNull($crawler->filter('#user_password_password')->attr('value'));
        self::assertStringNotContainsString($good, $this->body());
    }

    public function testASiteOnlyUserNeedsFourCharactersAndMayUseItsUsername(): void
    {
        $this->makeSiteOnly('shield');

        $crawler = $this->setPassword('shield', 'abc');
        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_password', 'password', self::SITE_ONLY_SHORT_PASSWORD);

        $crawler = $this->setPassword('shield', str_repeat('x', 129));
        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_password', 'password', self::LONG_PASSWORD);

        $this->setPassword('shield', 'wxyz');
        self::assertResponseRedirects('/admin/users/shield/edit', 303);
        self::assertStringContainsString('The password of "shield" was changed.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        $this->setPassword('shield', 'shield');
        self::assertResponseRedirects('/admin/users/shield/edit', 303);
        self::assertTrue(password_verify('shield', $this->user('shield')->passwordHash()));
    }

    public function testARegularUserNeedsTwelveCharactersEvenWhenTheSiteOnlyBoxWasTheOnlyThingAllowingLess(): void
    {
        $this->makeSiteOnly('shield');
        $this->save('shield', ['siteOnly' => null, 'email' => 'shield@example.com']);
        self::assertResponseRedirects('/admin/users/shield/edit', 303);

        $crawler = $this->setPassword('shield', 'abcd');

        self::assertResponseStatusCodeSame(422);
        $this->assertOnlyError($crawler, 'user_password', 'password', self::SHORT_PASSWORD);
        self::assertTrue(password_verify('abcd', $this->user('shield')->passwordHash()), 'the short password stays until it is replaced');
    }

    public function testADisabledUserIsMarkedEverywhereCanStillBeEditedAndIsEnabledAgain(): void
    {
        $this->makeUser('alice', displayName: 'Switch Me');
        $crawler = $this->open('/admin/users/alice/edit');
        $status = $crawler->filter('[data-testid=user-status]');
        self::assertStringContainsString('Active', $status->text());
        self::assertStringContainsString('A disabled user cannot sign in and loses access to every project.', $status->text());
        self::assertCount(0, $status->filter('form[action$=enable]'));

        $this->post('/admin/users/alice/disable', []);
        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User "alice" disabled.', $crawler->filter('[data-testid=flashes]')->text());
        $status = $crawler->filter('[data-testid=user-status]');
        self::assertStringContainsString('Disabled', $status->text());
        self::assertStringContainsString('This user cannot sign in. Enable the account to give the access back.', $status->text());
        self::assertCount(0, $status->filter('form[action$=disable]'));

        // Editing a disabled user keeps it disabled.
        $this->save('alice', ['displayName' => 'Still Off']);
        self::assertResponseRedirects('/admin/users/alice/edit', 303);
        self::assertFalse($this->user('alice')->isEnabled());
        $row = $this->open('/admin/users')->filter('[data-username=alice]');
        self::assertSame('Disabled', trim($row->filter('[data-testid=user-disabled]')->text()));
        self::assertStringContainsString('Still Off', $row->text());

        $this->post('/admin/users/alice/enable', []);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('User "alice" enabled.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertStringContainsString('Active', $crawler->filter('[data-testid=user-status]')->text());
        self::assertCount(0, $this->open('/admin/users')->filter('[data-username=alice] [data-testid=user-disabled]'));
    }

    public function testADeletedUsernameStartsAgainWithoutMembershipsAndItsPagesAre404(): void
    {
        $this->makeProject('blog');
        $this->makeUser('alice');
        $this->save('alice', ['projects' => ['blog']]);
        self::assertSame(['blog'], $this->projectsOf('alice'));

        $this->post('/admin/users/alice/delete', []);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertStringContainsString('User "alice" deleted.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());
        $this->open('/admin/users/alice/edit');
        self::assertResponseStatusCodeSame(404);

        $this->makeUser('alice');
        self::assertSame([], $this->projectsOf('alice'));
        self::assertSame('0 projects', trim($this->open('/admin/users')->filter('[data-username=alice] [data-testid=user-projects]')->text()));
        $crawler = $this->open('/admin/users/alice/edit');
        self::assertStringContainsString('The last enabled administrator cannot be deleted.', $crawler->filter('[data-testid=danger-zone]')->text());
        self::assertStringContainsString('Delete alice?', $crawler->filter('[data-testid=danger-zone]')->html());
    }

    public function testAMemberIsRefusedEveryUsersPageAndLearnsNothingAboutTheUsers(): void
    {
        $this->makeUser('alice', displayName: 'Hidden Person');
        $this->as('member');

        foreach (['/admin/users', '/admin/users/new', '/admin/users/alice/edit'] as $path) {
            $crawler = $this->open($path);
            self::assertResponseStatusCodeSame(403, $path);
            self::assertSame('Access denied', trim($crawler->filter('h1')->text()), $path);
            self::assertStringNotContainsString('Hidden Person', $this->body(), $path);
            self::assertCount(0, $crawler->filter('a[href="/admin/users"]'), $path.': no Users entry in any navigation');
        }

        $this->as('admin');
        $crawler = $this->open('/admin/users/alice/edit');
        self::assertStringContainsString('Active', $crawler->filter('[data-testid=user-status]')->text());
        self::assertSame('Hidden Person', $crawler->filter('#user_edit_displayName')->attr('value'));
    }

    public function testTheLastEnabledAdministratorIsGuardedWithItsExactMessagesAndADisabledOneDoesNotCount(): void
    {
        $this->makeUser('carol', admin: true);
        $guarded = function (): void {
            $this->post('/admin/users/carol/disable', []);
            self::assertResponseRedirects('/admin/users/carol/edit', 303);
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('The user was not disabled. "carol" is the last enabled administrator and cannot be disabled.', $crawler->filter('[data-testid=flashes]')->text());
            self::assertStringContainsString('Active', $crawler->filter('[data-testid=user-status]')->text());

            $this->post('/admin/users/carol/delete', []);
            self::assertResponseRedirects('/admin/users/carol/edit', 303);
            self::assertStringContainsString('The user was not deleted. "carol" is the last enabled administrator and cannot be deleted.', $this->client->followRedirect()->filter('[data-testid=flashes]')->text());

            $crawler = $this->save('carol', ['admin' => null]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('"carol" is the last enabled administrator and cannot be removed from the administrators.', trim($crawler->filter('[data-testid=user-form] [role=alert]')->text()));
            self::assertCount(1, $this->open('/admin/users/carol/edit')->filter('#user_edit_admin[checked]'));
        };

        $guarded();

        // A disabled administrator does not count: carol is the last enabled one again.
        $this->makeUser('erin', admin: true, enabled: false);
        $guarded();

        // Two enabled: either may be demoted, and the guard protects whoever is left.
        $this->post('/admin/users/erin/enable', []);
        self::assertResponseRedirects('/admin/users/erin/edit', 303);
        $this->save('erin', ['admin' => null]);
        self::assertResponseRedirects('/admin/users/erin/edit', 303);
        $guarded();
    }

    public function testYourOwnAccountIsProtectedWithItsExactMessagesButProfileAndPasswordCanChange(): void
    {
        $this->makeUser(self::SELF, admin: true, displayName: 'Spec keeper');
        $this->makeUser('carol', admin: true);

        $crawler = $this->open('/admin/users/'.self::SELF.'/edit');
        self::assertStringContainsString('You are signed in as this user. You cannot disable, demote or delete your own account.', $crawler->filter('[data-testid=user-status]')->text());
        self::assertCount(0, $crawler->filter('[data-testid=user-status] form'));
        self::assertCount(0, $crawler->filter('[data-testid=danger-zone]'));
        self::assertCount(1, $crawler->filter('#user_edit_admin[checked][disabled]'));
        self::assertStringContainsString('You cannot change your own role. Ask another administrator.', $crawler->text());

        $this->save(self::SELF, ['displayName' => 'Keeper Renamed']);
        self::assertResponseRedirects('/admin/users/'.self::SELF.'/edit', 303);
        self::assertSame('Keeper Renamed', $this->user(self::SELF)->displayName());
        self::assertSame(['fleet-admins'], $this->user(self::SELF)->groups());
        $this->setPassword(self::SELF, 'another long passphrase');
        self::assertResponseRedirects('/admin/users/'.self::SELF.'/edit', 303);

        foreach (['disable' => 'The user was not disabled. Your own account cannot be disabled.', 'delete' => 'The user was not deleted. Your own account cannot be deleted.'] as $action => $message) {
            $this->post('/admin/users/'.self::SELF.'/'.$action, []);
            self::assertResponseRedirects('/admin/users/'.self::SELF.'/edit', 303);
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString($message, $crawler->filter('[data-testid=flashes]')->text());
            self::assertStringContainsString('You are signed in', $crawler->filter('[data-testid=user-status]')->text());
        }
        self::assertTrue($this->user(self::SELF)->isEnabled());
        self::assertCount(0, $this->open('/admin/users')->filter('[data-username='.self::SELF.'] [data-testid=user-disabled]'));
    }
}
