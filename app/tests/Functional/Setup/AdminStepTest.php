<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Tests\Support\WizardTestCase;

final class AdminStepTest extends WizardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function values(array $override = []): array
    {
        return $override + [
            'admin_account[username]' => 'root',
            'admin_account[displayName]' => 'Root Admin',
            'admin_account[email]' => 'root@example.com',
            'admin_account[password][first]' => self::PASSWORD,
            'admin_account[password][second]' => self::PASSWORD,
        ];
    }

    public function testThePageHasOneHeadingAStepperAndNoPasswordValue(): void
    {
        $crawler = $this->open('/setup/admin');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('[aria-current="step"]'));
        self::assertSame('Admin', trim($crawler->filter('[aria-current="step"] span')->last()->text()));
        self::assertCount(5, $crawler->filter('[data-testid=setup-stepper] li'));
        self::assertSame('password', $crawler->filter('#admin_account_password_first')->attr('type'));
        self::assertSame('', (string) $crawler->filter('#admin_account_password_first')->attr('value'));
        self::assertStringNotContainsString('data-turbo="true"', $this->body());
    }

    public function testWeakPasswordRendersErrorAndStoresNothing(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', $this->values([
            'admin_account[password][first]' => 'short',
            'admin_account[password][second]' => 'short',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('at least 12 characters', $this->body());
        self::assertCount(0, $this->users()->all());
        self::assertStringNotContainsString('value="short"', $this->body());
    }

    public function testMismatchedConfirmation(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', $this->values(['admin_account[password][second]' => 'something else entirely']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('do not match', $this->body());
        self::assertStringNotContainsString(self::PASSWORD, $this->body());
        self::assertStringNotContainsString('something else entirely', $this->body());
        self::assertCount(0, $this->users()->all());
    }

    public function testFieldShapeErrorsAreFormErrorsNotExceptions(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', $this->values([
            'admin_account[username]' => 'Not Valid!',
            'admin_account[email]' => 'nope',
            'admin_account[displayName]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('lowercase letters', $this->body());
        self::assertStringContainsString('valid email', $this->body());
        self::assertStringContainsString('display name', $this->body());
        self::assertCount(0, $this->users()->all());
    }

    public function testValidPostStoresArgon2idHash(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', $this->values());

        self::assertResponseRedirects('/setup/tls');
        $users = $this->users()->all();
        self::assertCount(1, $users);
        self::assertSame('root', $users[0]->username());
        self::assertTrue($users[0]->isInGroup('fleet-admins'));
        self::assertStringStartsWith('$argon2id$', $users[0]->passwordHash());
        self::assertTrue(password_verify(self::PASSWORD, $users[0]->passwordHash()));
        self::assertStringNotContainsString(self::PASSWORD, $users[0]->passwordHash());
    }

    public function testSecondPostUpdatesSameUser(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', $this->values());

        $crawler = $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('root', $crawler->filter('#admin_account_username')->attr('value'));
        self::assertNotNull($crawler->filter('#admin_account_username')->attr('disabled'), 'the username is fixed once it exists');
        self::assertSame('Root Admin', $crawler->filter('#admin_account_displayName')->attr('value'));

        $form = $crawler->selectButton('Save and continue')->form([
            'admin_account[displayName]' => 'Renamed Admin',
            'admin_account[password][first]' => 'another long passphrase',
            'admin_account[password][second]' => 'another long passphrase',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/setup/tls');
        $users = $this->users()->all();
        self::assertCount(1, $users);
        self::assertSame('Renamed Admin', $users[0]->displayName());
        self::assertTrue(password_verify('another long passphrase', $users[0]->passwordHash()));
    }

    public function testAForgedUsernameCannotCreateASecondAdministrator(): void
    {
        $crawler = $this->open('/setup/admin');
        $this->submit($crawler, 'Save and continue', $this->values());
        $this->open('/setup/admin');

        $this->post('/setup/admin', ['admin_account' => [
            'username' => 'mallory',
            'displayName' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
            '_token' => 'csrf-token',
        ]]);

        $names = array_map(static fn ($u): string => $u->username(), $this->users()->all());
        self::assertSame(['root'], $names);
    }

    public function testACrossOriginPostIsRejected(): void
    {
        $this->open('/setup/admin');
        $this->post('/setup/admin', ['admin_account' => [
            'username' => 'root',
            'displayName' => 'Root Admin',
            'email' => 'root@example.com',
            'password' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
            '_token' => 'csrf-token',
        ]], fetchSite: 'cross-site');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->users()->all());
    }

    public function testAPostWithoutTheTokenFieldIsRejected(): void
    {
        $this->open('/setup/admin');
        $this->client->request('POST', self::BASE.'/setup/admin', ['admin_account' => [
            'username' => 'root',
            'displayName' => 'Root Admin',
            'email' => 'root@example.com',
            'password' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
        ]], server: $this->browserServer());

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->users()->all());
    }
}
