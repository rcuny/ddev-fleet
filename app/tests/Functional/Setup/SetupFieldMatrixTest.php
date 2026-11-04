<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Domain\Host\TlsMode;
use App\Tests\Support\WizardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every refusal message of the wizard's Administrator, TLS and Claude steps, case by case (FLE-162, test diet 5/7).
 * These matrices ran in the browser (setup-wizard-coverage.spec.ts); the browser keeps the walk, the copy buttons and
 * the layout. AdminStepTest, TlsStepTest and ClaudeStepTest keep the single-case checks.
 */
final class SetupFieldMatrixTest extends WizardTestCase
{
    private const string USERNAME_MESSAGE = 'Use 1 to 64 lowercase letters, digits, dots, underscores or dashes, starting with a letter or digit.';
    private const string KEY = 'AppKeyAAAA11';
    private const string SECRET = 'AppSecretBBBB22xyz';
    private const string CONSUMER = 'ConsumerCCCC33';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    /** The page text with entities decoded: messages hold quotes. */
    private function text(): string
    {
        return html_entity_decode($this->body());
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function admin(array $override = []): array
    {
        return $override + [
            'admin_account[username]' => 'cov-admin',
            'admin_account[displayName]' => 'Coverage Admin',
            'admin_account[email]' => 'cov-admin@fleet.example.com',
            'admin_account[password][first]' => self::PASSWORD,
            'admin_account[password][second]' => self::PASSWORD,
        ];
    }

    /**
     * @param array<string, string> $override
     */
    private function saveAdmin(array $override): void
    {
        $this->submit($this->open('/setup/admin'), 'Save and continue', $this->admin($override));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function usernames(): iterable
    {
        yield 'blank' => [['admin_account[username]' => ''], 'Enter a username.'];
        yield 'capital letters' => [['admin_account[username]' => 'Admin'], self::USERNAME_MESSAGE];
        yield 'a space' => [['admin_account[username]' => 'my admin'], self::USERNAME_MESSAGE];
        yield 'a leading dash' => [['admin_account[username]' => '-admin'], self::USERNAME_MESSAGE];
        yield 'a leading dot' => [['admin_account[username]' => '.admin'], self::USERNAME_MESSAGE];
        yield 'an accent' => [['admin_account[username]' => 'adminé'], self::USERNAME_MESSAGE];
        yield 'an at sign' => [['admin_account[username]' => 'admin@example'], self::USERNAME_MESSAGE];
        yield 'a slash' => [['admin_account[username]' => 'admin/root'], self::USERNAME_MESSAGE];
        yield '65 characters' => [['admin_account[username]' => str_repeat('a', 65)], self::USERNAME_MESSAGE];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('usernames')]
    public function testEveryUsernameRefusal(array $values, string $message): void
    {
        $this->saveAdmin($values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->text());
        self::assertSame('', (string) $this->client->getCrawler()->filter('#admin_account_password_first')->attr('value'));
        self::assertCount(0, $this->users()->all());
    }

    public function testSixtyFourCharactersDotsUnderscoresAndDashesAreAcceptedInAUsername(): void
    {
        $this->saveAdmin(['admin_account[username]' => 'a'.str_repeat('b', 60).'._-']);

        self::assertResponseRedirects('/setup/tls');
        self::assertCount(1, $this->users()->all());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function displayNamesAndEmails(): iterable
    {
        yield 'blank display name' => [['admin_account[displayName]' => ''], 'Enter a display name.'];
        yield 'a blank-looking display name' => [['admin_account[displayName]' => '   '], 'Enter a display name.'];
        yield '256-character display name' => [['admin_account[displayName]' => str_repeat('n', 256)], 'The display name can have at most 255 characters.'];
        yield 'a tab in the display name' => [['admin_account[displayName]' => "Cov\tAdmin"], 'The display name must be 1-255 characters without control characters.'];
        yield 'blank email' => [['admin_account[email]' => ''], 'Enter an email address.'];
        yield 'no at sign' => [['admin_account[email]' => 'nobody'], 'Enter a valid email address.'];
        yield 'no domain dot' => [['admin_account[email]' => 'nobody@localhost'], 'Enter a valid email address.'];
        yield 'two at signs' => [['admin_account[email]' => 'a@b@example.com'], 'Enter a valid email address.'];
        yield 'a space in the email' => [['admin_account[email]' => 'no body@example.com'], 'Enter a valid email address.'];
        yield 'a 255-character email' => [['admin_account[email]' => str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.example.com'], 'The email address is too long.'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('displayNamesAndEmails')]
    public function testEveryDisplayNameAndEmailRefusalKeepsTheOtherValues(array $values, string $message): void
    {
        $this->saveAdmin($values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->text());
        $form = $this->client->getCrawler();
        self::assertSame('cov-admin', $form->filter('#admin_account_username')->attr('value'));
        self::assertSame('', (string) $form->filter('#admin_account_password_first')->attr('value'));
        self::assertSame('', (string) $form->filter('#admin_account_password_second')->attr('value'));
        self::assertCount(0, $this->users()->all());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function passwords(): iterable
    {
        yield 'blank password' => [['admin_account[password][first]' => '', 'admin_account[password][second]' => ''], 'Enter a password.'];
        yield 'a mismatch' => [['admin_account[password][second]' => 'correct horse battery stapl3'], 'The two passwords do not match.'];
        yield '11 characters' => [['admin_account[password][first]' => 'abcdefghijk', 'admin_account[password][second]' => 'abcdefghijk'], 'The password must be at least 12 characters long.'];
        yield 'a short one' => [['admin_account[password][first]' => 'short', 'admin_account[password][second]' => 'short'], 'The password must be at least 12 characters long.'];
        yield '129 characters' => [['admin_account[password][first]' => str_repeat('p', 129), 'admin_account[password][second]' => str_repeat('p', 129)], 'The password must be at most 128 characters long.'];
        yield 'the username' => [['admin_account[username]' => 'administrator', 'admin_account[password][first]' => 'administrator', 'admin_account[password][second]' => 'administrator'], 'The password must not be the username.'];
        yield 'the username in capitals' => [['admin_account[username]' => 'administrator', 'admin_account[password][first]' => 'ADMINISTRATOR', 'admin_account[password][second]' => 'ADMINISTRATOR'], 'The password must not be the username.'];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('passwords')]
    public function testEveryPasswordRefusalAndThePasswordNeverComesBack(array $values, string $message): void
    {
        $this->saveAdmin($values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->text());
        $form = $this->client->getCrawler();
        self::assertSame('', (string) $form->filter('#admin_account_password_first')->attr('value'));
        self::assertSame('', (string) $form->filter('#admin_account_password_second')->attr('value'));
        $typed = $values['admin_account[password][first]'] ?? self::PASSWORD;
        if ('' !== $typed && ($values['admin_account[username]'] ?? '') !== $typed) {
            self::assertStringNotContainsString($typed, $this->body());
        }
        self::assertCount(0, $this->users()->all());
    }

    public function testTwoPasswordProblemsAreBothSaid(): void
    {
        $this->saveAdmin(['admin_account[username]' => 'shortpass', 'admin_account[password][first]' => 'shortpass', 'admin_account[password][second]' => 'shortpass']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The password must be at least 12 characters long.', $this->text());
        self::assertStringContainsString('The password must not be the username.', $this->text());
    }

    public function testTwelveAndOneHundredTwentyEightCharacterPasswordsAreAccepted(): void
    {
        $this->saveAdmin(['admin_account[password][first]' => str_repeat('p', 128), 'admin_account[password][second]' => str_repeat('p', 128)]);

        self::assertResponseRedirects('/setup/tls');
    }

    public function testASavedAdministratorComesBackWithALockedUsernameAndUpdatesKeepOneUser(): void
    {
        $this->saveAdmin([]);
        self::assertResponseRedirects('/setup/tls');
        self::assertCount(1, $this->users()->all());

        $crawler = $this->open('/setup/admin');
        self::assertStringContainsString('It already exists: saving again updates it.', $this->text());
        self::assertNotNull($crawler->filter('#admin_account_username')->attr('disabled'));
        self::assertSame('cov-admin', $crawler->filter('#admin_account_username')->attr('value'));
        self::assertSame('Coverage Admin', $crawler->filter('#admin_account_displayName')->attr('value'));
        self::assertSame('cov-admin@fleet.example.com', $crawler->filter('#admin_account_email')->attr('value'));
        self::assertSame('', (string) $crawler->filter('#admin_account_password_first')->attr('value'));
        self::assertStringNotContainsString(self::PASSWORD, $this->body());

        // The password is still required.
        $this->submit($crawler, 'Save and continue', ['admin_account[displayName]' => 'Coverage Admin', 'admin_account[email]' => 'cov-admin@fleet.example.com', 'admin_account[password][first]' => '', 'admin_account[password][second]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a password.', $this->text());

        // Saving again updates the same user; a username posted for the disabled field is ignored.
        $this->post('/setup/admin', ['admin_account' => [
            'username' => 'intruder',
            'displayName' => 'Renamed Admin',
            'email' => 'renamed@fleet.example.com',
            'password' => ['first' => 'another long passphrase', 'second' => 'another long passphrase'],
            '_token' => 'csrf-token',
        ]]);
        self::assertResponseRedirects('/setup/tls');
        $users = $this->users()->all();
        self::assertCount(1, $users);
        self::assertSame('cov-admin', $users[0]->username());
        self::assertSame('Renamed Admin', $users[0]->displayName());

        $this->open('/setup/finish');
        self::assertResponseRedirects('/setup/ssh');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ovhShapes(): iterable
    {
        yield 'seven characters' => ['abcdefg'];
        yield 'a space' => ['abcd efgh'];
        yield 'an exclamation mark' => ['abcdefgh!'];
        yield 'a slash' => ['abcd/efgh'];
        yield 'an accent' => ['abcdéfgh'];
        yield '129 characters' => [str_repeat('k', 129)];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function ovhCredentialShapes(): iterable
    {
        foreach (['tls[ovhApplicationKey]' => 'application key', 'tls[ovhApplicationSecret]' => 'application secret', 'tls[ovhConsumerKey]' => 'consumer key'] as $field => $noun) {
            foreach (self::ovhShapes() as $what => [$value]) {
                yield $noun.', '.$what => [$field, $noun, $value];
            }
        }
    }

    #[DataProvider('ovhCredentialShapes')]
    public function testEveryOvhCredentialMustHaveTheShapeOvhIssues(string $field, string $noun, string $value): void
    {
        $values = [
            'tls[mode]' => 'ovh_dns',
            'tls[ovhEndpoint]' => 'ovh-eu',
            'tls[ovhApplicationKey]' => self::KEY,
            'tls[ovhApplicationSecret]' => self::SECRET,
            'tls[ovhConsumerKey]' => self::CONSUMER,
        ];
        $values[$field] = $value;

        $this->submit($this->open('/setup/tls'), 'Save and continue', $values);

        self::assertResponseStatusCodeSame(422);
        $text = $this->text();
        self::assertStringContainsString(\sprintf('The OVH %s must be 8 to 128 characters of letters, digits, ".", "_" or "-".', $noun), $text);
        foreach (['application key', 'application secret', 'consumer key'] as $other) {
            if ($other !== $noun) {
                self::assertStringNotContainsString(\sprintf('The OVH %s must be', $other), $text, 'the others are not accused');
            }
        }
        self::assertSame('', (string) $this->client->getCrawler()->filter('input[name="'.$field.'"]')->attr('value'), 'the refused value is not put back');
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
    }

    public function testDnsModeNamesEveryMissingCredentialAndTheChoiceSurvives(): void
    {
        $this->submit($this->open('/setup/tls'), 'Save and continue', ['tls[mode]' => 'ovh_dns']);

        self::assertResponseStatusCodeSame(422);
        foreach (['application key', 'application secret', 'consumer key'] as $noun) {
            self::assertStringContainsString('Enter the OVH '.$noun.'.', $this->text());
        }
        self::assertSame('ovh_dns', $this->client->getCrawler()->filter('input[type=radio][name="tls[mode]"][checked]')->attr('value'));
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
    }

    public function testHttp01IgnoresTheOvhFieldsAndKeepsNothingOfThem(): void
    {
        $this->submit($this->open('/setup/tls'), 'Save and continue', ['tls[mode]' => 'http01', 'tls[ovhApplicationKey]' => 'x', 'tls[ovhConsumerKey]' => 'not valid!']);

        self::assertResponseRedirects('/setup/ssh');
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
        self::assertFalse($this->secretStore()->hasFleetWide('ovh_application_key'));
        $crawler = $this->open('/setup/tls');
        self::assertStringNotContainsString('Stored. Leave blank to keep it.', $this->text());
        self::assertSame('', (string) $crawler->filter('input[name="tls[ovhApplicationKey]"]')->attr('value'));
    }

    public function testTheShortestTheLongestAndAPaddedCredentialAreAccepted(): void
    {
        $this->submit($this->open('/setup/tls'), 'Save and continue', [
            'tls[mode]' => 'ovh_dns',
            'tls[ovhApplicationKey]' => 'abcdefgh',
            'tls[ovhApplicationSecret]' => str_repeat('S', 128),
            'tls[ovhConsumerKey]' => '  padded.value_ok-1  ',
        ]);

        self::assertResponseRedirects('/setup/ssh');
        self::assertSame(TlsMode::OvhDns, $this->host()->tlsMode());
        self::assertSame('padded.value_ok-1', $this->secretStore()->getFleetWide('ovh_consumer_key'));
        self::assertSame(str_repeat('S', 128), $this->secretStore()->getFleetWide('ovh_application_secret'));
    }

    public function testAForgedModeIsRefused(): void
    {
        $this->open('/setup/tls');
        $this->post('/setup/tls', ['tls' => ['mode' => 'carrier-pigeon', 'ovhEndpoint' => 'ovh-eu', '_token' => 'csrf-token']]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose one of the two modes.', $this->text());
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
    }

    public function testAForgedEndpointSaysSo(): void
    {
        $this->open('/setup/tls');
        $this->post('/setup/tls', ['tls' => [
            'mode' => 'ovh_dns',
            'ovhEndpoint' => 'ovh-xx',
            'ovhApplicationKey' => self::KEY,
            'ovhApplicationSecret' => self::SECRET,
            'ovhConsumerKey' => self::CONSUMER,
            '_token' => 'csrf-token',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose one of the listed OVH endpoints.', $this->text());
    }

    public function testStoredOvhCredentialsAreNeverShownABlankFieldKeepsThemAndANewValueReplacesOne(): void
    {
        $this->submit($this->open('/setup/tls'), 'Save and continue', [
            'tls[mode]' => 'ovh_dns',
            'tls[ovhEndpoint]' => 'ovh-ca',
            'tls[ovhApplicationKey]' => self::KEY,
            'tls[ovhApplicationSecret]' => self::SECRET,
            'tls[ovhConsumerKey]' => self::CONSUMER,
        ]);
        self::assertResponseRedirects('/setup/ssh');

        $crawler = $this->open('/setup/tls');
        self::assertSame('ovh_dns', $crawler->filter('input[type=radio][name="tls[mode]"][checked]')->attr('value'));
        self::assertSame('ovh-ca', $crawler->filter('select[name="tls[ovhEndpoint]"] option[selected]')->attr('value'));
        self::assertSame(3, substr_count($this->text(), 'Stored. Leave blank to keep it.'));
        foreach ([self::KEY, self::SECRET, self::CONSUMER] as $value) {
            self::assertStringNotContainsString($value, $this->body());
        }

        $this->submit($crawler, 'Save and continue', []);
        self::assertResponseRedirects('/setup/ssh');
        self::assertSame(self::SECRET, $this->secretStore()->getFleetWide('ovh_application_secret'));

        $this->submit($this->open('/setup/tls'), 'Save and continue', ['tls[ovhConsumerKey]' => 'replacement-consumer-key']);
        self::assertResponseRedirects('/setup/ssh');
        self::assertSame('replacement-consumer-key', $this->secretStore()->getFleetWide('ovh_consumer_key'));
        self::assertSame(self::KEY, $this->secretStore()->getFleetWide('ovh_application_key'));

        $this->submit($this->open('/setup/tls'), 'Save and continue', ['tls[mode]' => 'http01']);
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
        $this->open('/setup/tls');
        self::assertSame(3, substr_count($this->text(), 'Stored. Leave blank to keep it.'), 'the credentials stay stored for a later change of mind');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function claudeTokens(): iterable
    {
        yield 'three characters' => ['abc'];
        yield '19 characters' => [str_repeat('t', 19)];
        yield 'an inner space' => ['sk-ant-oat01 coverage-token-0123456789'];
        yield 'a dollar sign' => ['sk-ant-oat01-coverage$token-0123456789'];
        yield 'a quote' => ['sk-ant-oat01-"coverage-token-0123456789"'];
        yield 'an accent' => ['sk-ant-oat01-coverage-tokén-0123456789'];
        yield '513 characters' => [str_repeat('t', 513)];
    }

    #[DataProvider('claudeTokens')]
    public function testATokenOfTheWrongShapeIsRefusedAndNotEchoed(string $token): void
    {
        $this->submit($this->open('/setup/claude'), 'Save and continue', ['claude_token[token]' => $token]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('That does not look like a Claude token. Copy it again from "claude setup-token".', $this->text());
        self::assertSame('', (string) $this->client->getCrawler()->filter('input[name="claude_token[token]"]')->attr('value'));
        self::assertStringNotContainsString(substr($token, 0, 30), $this->body());
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
    }

    public function testABlankSaveIsToldToPasteOrSkip(): void
    {
        $this->submit($this->open('/setup/claude'), 'Save and continue', ['claude_token[token]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Paste the token, or press "Skip for now".', $this->text());
    }

    public function testTheTwentyAndFiveHundredTwelveCharacterEdgesAreStoredTrimmedAndReplaceEachOther(): void
    {
        $long = str_repeat('a._~+/=-', 64);
        foreach ([str_repeat('t', 20), $long] as $token) {
            $this->submit($this->open('/setup/claude'), 'Save and continue', ['claude_token[token]' => '  '.$token.'  ']);
            self::assertResponseRedirects('/setup/finish');
            self::assertSame($token, $this->secretStore()->getFleetWide('claude_oauth_token'));
        }

        $this->open('/setup/claude');
        self::assertStringContainsString('A token is stored. Leave blank to keep it, or paste a new one to replace it.', $this->text());
        self::assertStringNotContainsString($long, $this->body());
    }

    public function testTheStepsMarkTheNextOpenStepAndBackNavigationKeepsTheAnswers(): void
    {
        $this->saveAdmin([]);
        $this->submit($this->open('/setup/tls'), 'Save and continue', ['tls[mode]' => 'http01']);
        self::assertResponseRedirects('/setup/ssh');

        $crawler = $this->open('/setup/admin');
        self::assertSame('Coverage Admin', $crawler->filter('#admin_account_displayName')->attr('value'));
        $crawler = $this->open('/setup/tls');
        self::assertSame('http01', $crawler->filter('input[type=radio][name="tls[mode]"][checked]')->attr('value'));
        $hrefs = $this->open('/setup/admin')->filter('[data-testid=setup-stepper] a')->each(static fn ($link): string => (string) parse_url((string) $link->attr('href'), \PHP_URL_PATH));
        self::assertSame(['/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish'], $hrefs);
    }
}
