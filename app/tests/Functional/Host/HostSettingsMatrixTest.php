<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Tests\Support\HostPageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The host settings page, assertion by assertion: every tab's texts, every refusal message the rules return and where
 * the page puts it, and the saved state read back. HostPageTest covers the flows (status codes, secrets, audit rows);
 * this class holds what the browser specs host-settings*.spec.ts used to check over HTTP (test diet 7/7, FLE-164).
 */
final class HostSettingsMatrixTest extends HostPageTestCase
{
    private const int MAX_UPLOAD_MIB = 1_048_576;
    private const string TOKEN_MESSAGE = 'A token has no spaces or control characters and is at most 512 characters. Copy it again from "claude setup-token".';

    private const array TABS = [
        'general' => 'General',
        'git' => 'Git identity',
        'claude' => 'Claude token',
        'notifications' => 'Notifications',
        'network' => 'Networking',
        'certificates' => 'Certificates',
        'uploads' => 'Uploads',
        'jobs' => 'Jobs',
        'capacity' => 'Capacity',
        'hardening' => 'Hardening',
        'backups' => 'Backups',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tabs(): iterable
    {
        foreach (self::TABS as $key => $label) {
            yield $label => [$key, $label];
        }
    }

    #[DataProvider('tabs')]
    public function testEachTabLinkMarksItselfCurrentAndOpensItsTab(string $key, string $label): void
    {
        $crawler = $this->open('/admin/host?tab='.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=host-tab-'.$key.']'));
        self::assertSame([$label], $crawler->filter('[data-testid=host-tabs] a[aria-current=page]')->each(static fn (Crawler $a): string => trim($a->text())));
        $href = (string) $crawler->filter('[data-testid=host-tabs]')->selectLink($label)->link()->getUri();
        self::assertStringEndsWith('/admin/host?tab='.$key, $href);
    }

    public function testThePhoneSelectListsTheTabsNamesTheCurrentOneAndGoesToAnother(): void
    {
        $crawler = $this->open('/admin/host?tab=uploads');
        $select = $crawler->filter('[data-testid=host-tab-select] select');

        self::assertSame(array_values(self::TABS), $select->filter('option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame(array_keys(self::TABS), $select->filter('option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('uploads', $select->filter('option[selected]')->attr('value'));
        self::assertSame('Section', trim($crawler->filter('[data-testid=host-tab-select] label')->text()));

        $form = $crawler->filter('[data-testid=host-tab-select]')->selectButton('Go')->form(['tab' => 'backups']);
        self::assertSame('GET', $form->getMethod());
        $crawler = $this->client->submit($form);
        self::assertSame('backups', $this->client->getRequest()->query->get('tab'));
        self::assertCount(1, $crawler->filter('[data-testid=host-tab-backups]'));
    }

    public function testGeneralIsReadOnlyAndExplainsHowToChangeIt(): void
    {
        $crawler = $this->open('/admin/host?tab=general');
        $tab = $crawler->filter('[data-testid=host-tab-general]');

        self::assertMatchesRegularExpression('/\S+\.\S+/', $tab->filter('[data-testid=host-domain]')->text());
        self::assertMatchesRegularExpression('/http01|ovh_dns/', $tab->filter('[data-testid=host-tls-mode]')->text());
        self::assertCount(1, $tab->filter('input:not([type=hidden])'), 'the ACME contact is the only editable field');
        self::assertStringContainsString('Changing it after setup is not supported yet', $tab->filter('[data-testid=host-general-cli]')->text());
        self::assertStringContainsString('The address the certificate authority may use to warn about expiring certificates. Optional.', $this->squash($tab->text()));
        self::assertStringContainsString('After saving, apply the new address to Caddy with sudo fleet host:render --apply on the host.', $this->squash($tab->text()));
        // The browser stops a bad address before any request: the field is an e-mail field.
        self::assertSame('email', $tab->filter('input[name="host_general[acmeEmail]"]')->attr('type'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAcmeEmails(): iterable
    {
        yield 'no at sign' => ['nobody'];
        yield 'no dot in the domain' => ['a@b'];
        yield 'a space' => ['two words@example.com'];
        yield 'too long' => [str_repeat('a', 250).'@example.com'];
    }

    #[DataProvider('badAcmeEmails')]
    public function testABadAcmeContactIsRefusedWithItsMessageAndNothingIsSaved(string $bad): void
    {
        $this->submit('general', 'Save', ['host_general[acmeEmail]' => $bad]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('That is not a valid e-mail address.', $this->errorOf('general', 'host_general[acmeEmail]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=flashes]'));
        self::assertNull($this->host()->acmeEmail());
    }

    public function testAnAcmeContactIsSavedReadBackAndClearedWithItsFlash(): void
    {
        $flash = 'ACME contact saved. Apply it to Caddy with "sudo fleet host:render --apply" on the host.';

        $this->submit('general', 'Save', ['host_general[acmeEmail]' => 'certs@example.com']);
        self::assertSame($flash, $this->flash());
        self::assertSame('certs@example.com', $this->open('/admin/host?tab=general')->filter('input[name="host_general[acmeEmail]"]')->attr('value'));

        $this->submit('general', 'Save', ['host_general[acmeEmail]' => '']);
        self::assertStringContainsString('ACME contact saved.', $this->flash());
        self::assertSame('', (string) $this->open('/admin/host?tab=general')->filter('input[name="host_general[acmeEmail]"]')->attr('value'));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function gitRefusals(): iterable
    {
        $both = 'Set both the name and the e-mail address, or leave both empty.';
        $control = 'The name must not contain control characters, "<" or ">".';
        $mail = 'Enter a valid email address.';

        yield 'an e-mail without a name' => ['name', '', 'bot@example.com', $both];
        yield 'a name without an e-mail' => ['email', 'Fleet Bot', '', $both];
        yield 'a name of 101 characters' => ['name', str_repeat('n', 101), 'bot@example.com', 'The name must be at most 100 characters.'];
        yield 'angle brackets' => ['name', 'Fleet <Bot>', 'bot@example.com', $control];
        yield 'a tab' => ['name', "Fleet\tBot", 'bot@example.com', $control];
        yield 'localhost' => ['email', 'Fleet Bot', 'bot@localhost', $mail];
        yield 'not an address' => ['email', 'Fleet Bot', 'not an address', $mail];
        yield 'a local part of 70 characters' => ['email', 'Fleet Bot', str_repeat('a', 70).'@example.com', $mail];
    }

    #[DataProvider('gitRefusals')]
    public function testEveryGitIdentityRefusalHasItsMessageOnTheRightField(string $field, string $name, string $email, string $message): void
    {
        $this->submit('git', 'Save', ['host_git_identity[name]' => $name, 'host_git_identity[email]' => $email]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->errorOf('git', 'host_git_identity['.$field.']'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=flashes]'));
        self::assertNull($this->host()->gitUserName());
    }

    public function testASavedGitIdentityIsTrimmedKeepsUnicodeAndFlipsTheWorkspaceCheck(): void
    {
        $this->submit('git', 'Save', ['host_git_identity[name]' => '  Flëet Bøt 日本  ', 'host_git_identity[email]' => '  bot@example.com ']);
        self::assertSame('Git identity saved.', $this->flash());
        $crawler = $this->client->getCrawler();
        self::assertSame('Flëet Bøt 日本', $crawler->filter('input[name="host_git_identity[name]"]')->attr('value'));
        self::assertSame('bot@example.com', $crawler->filter('input[name="host_git_identity[email]"]')->attr('value'));
        self::assertStringContainsString('Present', $crawler->filter('[data-testid=host-check-git-identity]')->text());
        self::assertCount(0, $crawler->filter('[data-testid=host-git-stored-invalid]'));

        $this->submit('git', 'Save', ['host_git_identity[name]' => '', 'host_git_identity[email]' => '']);
        self::assertSame('Git identity saved.', $this->flash());
        $crawler = $this->client->getCrawler();
        self::assertSame('', (string) $crawler->filter('input[name="host_git_identity[name]"]')->attr('value'));
        self::assertStringContainsString('Missing', $crawler->filter('[data-testid=host-check-git-identity]')->text());
    }

    public function testAnEmptyTokenIsAskedFor(): void
    {
        $this->submit('claude', 'Store token', ['host_claude_token[token]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Paste the token to store it.', $this->tabText('claude'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'a space' => ['sk-ant-oat01-unique-secret-1 with space'];
        yield 'a tab' => ["sk-ant-oat01-unique-secret-1\twith-tab"];
        yield '520 characters more' => ['sk-ant-oat01-unique-secret-1'.str_repeat('x', 520)];
        yield 'a non-ASCII letter' => ['é-sk-ant-oat01-unique-secret-1'];
    }

    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenHasItsMessageIsNeverShownBackAndTheFieldIsEmpty(string $bad): void
    {
        $this->submit('claude', 'Store token', ['host_claude_token[token]' => $bad]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::TOKEN_MESSAGE, $this->tabText('claude'));
        self::assertStringNotContainsString('unique-secret-1', $this->body());
        self::assertSame('', (string) $this->client->getCrawler()->filter('input[type=password]')->attr('value'));
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
    }

    public function testATokenOfTheLongestAllowedSizeIsStoredThenRotatedAndBothRotationsAreAudited(): void
    {
        $longest = 'sk-'.str_repeat('a', 509);
        self::assertSame(512, \strlen($longest));

        $this->submit('claude', 'Store token', ['host_claude_token[token]' => $longest]);
        self::assertSame('Claude token stored.', $this->flash());
        self::assertSame($longest, $this->secretStore()->getFleetWide('claude_oauth_token'));

        // After the first store the form says "New" and "Replace".
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('label:contains("New Claude OAuth token")'));
        self::assertCount(1, $crawler->filter('[data-testid=host-claude-form] button:contains("Replace token")'));
        self::assertStringContainsString('Set', $crawler->filter('[data-testid=host-claude-status]')->text());
        self::assertMatchesRegularExpression('/Last rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/', $crawler->filter('[data-testid=host-claude-status]')->text());
        self::assertStringContainsString('Present', $crawler->filter('[data-testid=host-check-claude-token]')->text());
        self::assertStringNotContainsString($longest, $this->body());

        $this->submit('claude', 'Replace token', ['host_claude_token[token]' => 'sk-ant-oat01-rotated-0123456789']);
        self::assertSame('Claude token stored.', $this->flash());

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT action, subject FROM audit_entry');
        self::assertSame(array_fill(0, 2, ['action' => 'claude.token.rotate', 'subject' => 'fleet/claude_oauth_token']), $rows);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function notificationRefusals(): iterable
    {
        $host = 'Enter a host name such as smtp.example.com (no scheme, port or path).';
        $user = 'The user name must be at most 254 characters without control characters.';
        $mail = 'That is not a valid e-mail address.';
        $password = 'The password must be 1 to 256 characters without control characters.';
        $port = 'The port must be between 1 and 65535.';

        yield 'a scheme' => ['smtpHost', ['smtpHost' => 'smtp://mail.example.com'], $host];
        yield 'a port in the host' => ['smtpHost', ['smtpHost' => 'mail.example.com:587'], $host];
        yield 'a leading dash' => ['smtpHost', ['smtpHost' => '-bad.example.com'], $host];
        yield 'a space in the host' => ['smtpHost', ['smtpHost' => 'mail example.com'], $host];
        yield 'port 0' => ['smtpPort', ['smtpHost' => 'mail.example.com', 'smtpPort' => '0'], $port];
        yield 'port 65536' => ['smtpPort', ['smtpHost' => 'mail.example.com', 'smtpPort' => '65536'], $port];
        yield 'a tab in the user' => ['smtpUser', ['smtpHost' => 'mail.example.com', 'smtpUser' => "a\tb"], $user];
        yield 'a user of 255 characters' => ['smtpUser', ['smtpHost' => 'mail.example.com', 'smtpUser' => str_repeat('u', 255)], $user];
        yield 'a sender without an at sign' => ['mailFrom', ['mailFrom' => 'nobody'], $mail];
        yield 'a sender with a space' => ['mailFrom', ['mailFrom' => 'a b@example.com'], $mail];
        yield 'a recipient too long' => ['mailTo', ['mailTo' => str_repeat('r', 250).'@example.com'], $mail];
        yield 'a password of 257 characters' => ['smtpPassword', ['smtpPassword' => str_repeat('p', 257)], $password];
        yield 'a tab in the password' => ['smtpPassword', ['smtpPassword' => "pass\tword"], $password];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('notificationRefusals')]
    public function testEveryNotificationFieldHasItsMessageAndNothingIsSaved(string $field, array $values, string $message): void
    {
        $post = [];
        foreach ($values as $name => $value) {
            $post['host_notification['.$name.']'] = $value;
        }
        $this->submit('notifications', 'Save', $post);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->errorOf('notifications', 'host_notification['.$field.']'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=flashes]'));
        self::assertNull($this->host()->smtpHost());
        self::assertFalse($this->secretStore()->hasFleetWide('smtp_password'));
    }

    public function testNotificationSettingsAreReadBackAndTheStoredPasswordIsReplacedRemovedAndRefusedWithBoth(): void
    {
        $password = 'smtp-first-unique-4471';
        $replacement = 'smtp-second-unique-9921';
        $saved = 'Notification e-mail settings saved.';

        $this->submit('notifications', 'Save', [
            'host_notification[smtpHost]' => 'smtp.example.com',
            'host_notification[smtpPort]' => '2525',
            'host_notification[smtpUser]' => 'mailer@example.com',
            'host_notification[smtpPassword]' => $password,
            'host_notification[mailFrom]' => 'fleet@example.com',
            'host_notification[mailTo]' => 'ops@example.com',
        ]);
        self::assertSame($saved, $this->flash());
        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('Set', $crawler->filter('[data-testid=host-smtp-status]')->text());
        self::assertMatchesRegularExpression('/Updated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/', $crawler->filter('[data-testid=host-smtp-status]')->text());

        // With a stored password the label says "New", the field is empty and a checkbox offers to remove it.
        $crawler = $this->open('/admin/host?tab=notifications');
        foreach (['smtpHost' => 'smtp.example.com', 'smtpPort' => '2525', 'smtpUser' => 'mailer@example.com', 'mailFrom' => 'fleet@example.com', 'mailTo' => 'ops@example.com'] as $field => $value) {
            self::assertSame($value, $crawler->filter('input[name="host_notification['.$field.']"]')->attr('value'), $field);
        }
        self::assertCount(1, $crawler->filter('label:contains("New SMTP password")'));
        self::assertCount(1, $crawler->filter('label:contains("Remove the stored password")'));
        self::assertStringContainsString('A password is stored. Leave blank to keep it, or type a new one to replace it.', $this->squash($crawler->filter('[data-testid=host-tab-notifications]')->text()));

        // A new password together with "remove" is refused with its message, and nothing changes.
        $form = $crawler->selectButton('Save')->form(['host_notification[smtpPassword]' => $replacement]);
        $this->tick($form, 'host_notification[clearPassword]');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Type a new password or remove the stored one, not both.', $this->tabText('notifications'));
        self::assertStringContainsString('Set', $this->client->getCrawler()->filter('[data-testid=host-smtp-status]')->text());
        self::assertSame($password, $this->secretStore()->getFleetWide('smtp_password'));

        $this->submit('notifications', 'Save', ['host_notification[smtpPassword]' => $replacement]);
        self::assertSame($saved, $this->flash());
        self::assertSame($replacement, $this->secretStore()->getFleetWide('smtp_password'));
        self::assertStringNotContainsString($replacement, $this->body());

        $crawler = $this->open('/admin/host?tab=notifications');
        $form = $crawler->selectButton('Save')->form();
        $this->tick($form, 'host_notification[clearPassword]');
        $this->client->submit($form);
        self::assertSame($saved, $this->flash());
        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('Not set', $crawler->filter('[data-testid=host-smtp-status]')->text());
        self::assertCount(0, $crawler->filter('label:contains("Remove the stored password")'));

        // Everything empty turns the e-mail off.
        $this->submit('notifications', 'Save', [
            'host_notification[smtpHost]' => '', 'host_notification[smtpPort]' => '', 'host_notification[smtpUser]' => '',
            'host_notification[mailFrom]' => '', 'host_notification[mailTo]' => '',
        ]);
        self::assertSame($saved, $this->flash());
        $host = $this->host();
        self::assertSame([null, null, null, null, null], [$host->smtpHost(), $host->smtpPort(), $host->smtpUser(), $host->mailFrom(), $host->mailTo()]);

        $rows = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_entry WHERE subject = 'fleet/smtp_password' ORDER BY id");
        self::assertSame(['secret.put', 'secret.put', 'secret.delete'], $rows);
        self::assertStringNotContainsString($password.$replacement, json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function testTheNotificationFieldsAreTypedSoTheBrowserStopsABadValueFirst(): void
    {
        $crawler = $this->open('/admin/host?tab=notifications');

        self::assertSame('email', $crawler->filter('input[name="host_notification[mailFrom]"]')->attr('type'));
        self::assertSame('email', $crawler->filter('input[name="host_notification[mailTo]"]')->attr('type'));
        $port = $crawler->filter('input[name="host_notification[smtpPort]"]');
        self::assertSame('number', $port->attr('type'));
        self::assertSame(['1', '65535'], [$port->attr('min'), $port->attr('max')]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badUploadLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'over a TiB' => [(string) (self::MAX_UPLOAD_MIB + 1)];
        yield 'empty' => [''];
    }

    #[DataProvider('badUploadLimits')]
    public function testAnUploadLimitOutOfRangeHasItsMessage(string $bad): void
    {
        $this->submit('uploads', 'Save', ['host_upload_limit[megabytes]' => $bad]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a number of MiB from 1 to '.self::MAX_UPLOAD_MIB.'.', $this->tabText('uploads'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=flashes]'));
    }

    public function testTheUploadLimitIsExplainedSavedAndShownInTheBiggestFittingUnit(): void
    {
        $crawler = $this->open('/admin/host?tab=uploads');
        self::assertStringContainsString('1 to '.self::MAX_UPLOAD_MIB.'. Bigger files are copied into the assets directory over SSH.', $this->squash($crawler->filter('[data-testid=host-tab-uploads]')->text()));

        foreach (['4096' => '4.0 GiB', '1' => '1.0 MiB', (string) self::MAX_UPLOAD_MIB => '1.0 TiB'] as $mebibytes => $shown) {
            $this->submit('uploads', 'Save', ['host_upload_limit[megabytes]' => (string) $mebibytes]);
            self::assertSame('Upload limit saved.', $this->flash());
            $crawler = $this->client->getCrawler();
            self::assertSame((string) $mebibytes, $crawler->filter('input[name="host_upload_limit[megabytes]"]')->attr('value'));
            self::assertSame($shown, trim($crawler->filter('[data-testid=host-upload-limit-current]')->text()));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRetentions(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'one over' => ['3651'];
        yield 'empty' => [''];
    }

    #[DataProvider('badRetentions')]
    public function testARetentionOutOfRangeHasItsMessage(string $bad): void
    {
        $this->submit('backups', 'Save', ['host_backup_retention[days]' => $bad]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a number of days from 1 to 3650.', $this->tabText('backups'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=flashes]'));
        self::assertSame(14, $this->host()->backupRetentionDays());
    }

    public function testTheRetentionBoundsAreAcceptedAndReadBack(): void
    {
        self::assertStringContainsString('1 to 3650. The newest backup is always kept, whatever its age.', $this->squash($this->open('/admin/host?tab=backups')->filter('[data-testid=host-tab-backups]')->text()));

        foreach (['1', '3650'] as $days) {
            $this->submit('backups', 'Save', ['host_backup_retention[days]' => $days]);
            self::assertSame('Backup retention saved.', $this->flash());
            self::assertSame($days, $this->open('/admin/host?tab=backups')->filter('input[name="host_backup_retention[days]"]')->attr('value'));
        }
    }

    public function testTheBackupListShowsDateSizeAndDownloadWithItsHeadersAndTheFlashNamesTheFile(): void
    {
        $crawler = $this->open('/admin/host?tab=backups');
        $tab = $this->squash($crawler->filter('[data-testid=host-tab-backups]')->text());
        self::assertStringContainsString('A snapshot of the fleet database is written every day.', $tab);
        self::assertStringContainsString('keys/secret.key', $tab);

        $this->client->submit($crawler->filter('[data-testid=backup-now-form]')->form());
        self::assertMatchesRegularExpression('/^Backup written: fleet-\d{8}-\d{6}\.sqlite \(\d+(\.\d)? (B|KiB|MiB)\)\.$/', $this->flash());

        $crawler = $this->client->getCrawler();
        self::assertSame(['Date (UTC)', 'Size', 'Download'], $crawler->filter('[data-testid=backups-table] th')->each(static fn (Crawler $th): string => trim($th->text())));
        $cells = $crawler->filter('[data-testid=backup-row]')->first()->filter('td');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', trim($cells->eq(0)->text()));
        self::assertNotSame('', trim($cells->eq(1)->text()));
        self::assertMatchesRegularExpression('/^Download fleet-\d{8}-\d{6}\.sqlite$/', trim($cells->eq(2)->filter('a')->attr('aria-label') ?? $cells->eq(2)->text()));
        $this->client->request('GET', (string) $cells->eq(2)->filter('a')->attr('href'));
        self::assertMatchesRegularExpression('/attachment; filename="?fleet-\d{8}-\d{6}\.sqlite"?/', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheNetworkingTabExplainsTheMirrorAndShowsItsState(): void
    {
        $crawler = $this->open('/admin/host?tab=network');
        $tab = $this->squash($crawler->filter('[data-testid=host-tab-network]')->text());

        self::assertStringContainsString("Named ports exist on the host's Caddy only.", $tab);
        self::assertStringContainsString('Switching it off removes the mirror files at once', $tab);
        self::assertStringContainsString('fleet port:refresh', $tab);
        self::assertCount(1, $crawler->filter('label:contains("Mirror the named ports inside the Docker network")'));
        self::assertMatchesRegularExpression('/The mirror is off|No port is mirrored now|Mirrored public ports/', $crawler->filter('[data-testid=host-mirror-ports]')->text());
    }

    public function testAMemberSeesTheRefusalPageAndNoTab(): void
    {
        $this->as('member');
        $crawler = $this->open('/admin/host');

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('You do not have access to this page', $this->body());
        self::assertCount(0, $crawler->filter('[data-testid=host-tab-general]'));
    }

    private function tick(Form $form, string $name): void
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function tabText(string $tab): string
    {
        return $this->squash($this->client->getCrawler()->filter('[data-testid=host-tab-'.$tab.']')->text());
    }

    /**
     * The flash after a redirect (the client follows it once), squashed.
     */
    private function flash(): string
    {
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
        $flashes = $this->client->getCrawler()->filter('[data-testid=flashes]');
        self::assertCount(1, $flashes, 'a flash is shown');

        return $this->squash($flashes->text());
    }

    /**
     * The error message shown in the row of the field named $name on the last response ('' when none).
     */
    private function errorOf(string $tab, string $name): string
    {
        $crawler = $this->client->getCrawler()->filter('[data-testid=host-tab-'.$tab.']');
        $row = $crawler->filterXPath('//*[@name="'.$name.'"]/ancestor::*[@data-slot="form-row" or contains(concat(" ", normalize-space(@class), " "), " grid ")][1]');
        self::assertCount(1, $row, 'the row of '.$name);

        return $this->squash($row->text());
    }
}
