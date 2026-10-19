<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Ssh\SshKeyRole;
use App\Fleet\Backup\BackupKind;
use App\Fleet\Backup\BackupService;
use App\Tests\Support\HostPageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class HostPageTest extends HostPageTestCase
{
    private const string TOKEN = 'sk-ant-oat01-Zq9XfLk2MnB7vC4dRt8YwHe3JuPa5GsAx1-secret';
    private const string SMTP_PASSWORD = 'smtp pass word 7Hk!x-unique-9931';

    public function testTabsAndTheFallbackToGeneral(): void
    {
        $crawler = $this->open('/admin/host?tab=nonsense');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=host-tab-general]'));

        $tabs = $crawler->filter('[data-testid=host-tabs] a');
        self::assertSame(['General', 'Git identity', 'Claude token', 'Notifications', 'Networking', 'Uploads', 'Backups'], $tabs->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertSame('page', $crawler->filter('[data-testid=host-tabs] a[aria-current]')->attr('aria-current'));
        // The phone version of the tabs: a native select with a Go button, no script needed.
        self::assertCount(7, $crawler->filter('[data-testid=host-tab-select] option'));
        self::assertCount(1, $crawler->filter('[data-testid=host-tab-select] button[type=submit]'));
        self::assertCount(0, $crawler->filter('main script'));

        foreach (['git', 'claude', 'notifications', 'network', 'uploads', 'backups'] as $tab) {
            $crawler = $this->open('/admin/host?tab='.$tab);
            self::assertResponseStatusCodeSame(200, $tab);
            self::assertCount(1, $crawler->filter('[data-testid=host-tab-'.$tab.']'), $tab);
        }
    }

    public function testGeneralShowsDomainTlsModeAndTheDocumentedCommands(): void
    {
        $crawler = $this->open('/admin/host');

        self::assertSame('fleet.example.com', trim($crawler->filter('[data-testid=host-domain] strong')->text()));
        self::assertSame('http01', trim($crawler->filter('[data-testid=host-tls-mode] strong')->text()));
        $cli = $crawler->filter('[data-testid=host-general-cli]')->text();
        self::assertStringContainsString('sudo fleet setup:reset', $cli);
        self::assertStringContainsString('sudo fleet host:init --tls-mode=http01 --force && sudo fleet host:render --apply && sudo systemctl restart caddy', $cli);
        // Domain and TLS mode are read-only here.
        self::assertCount(0, $crawler->filter('input[name*=domain], input[name*=tls]'));
    }

    public function testAcmeEmailIsSavedValidatedAndCleared(): void
    {
        $this->submit('general', 'Save', ['host_general[acmeEmail]' => '  ops@example.com ']);
        self::assertResponseRedirects('/admin/host?tab=general');
        self::assertSame('ops@example.com', $this->host()->acmeEmail());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('ACME contact saved', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('ops@example.com', $crawler->filter('input[name="host_general[acmeEmail]"]')->attr('value'));

        // Passes the browser's loose e-mail check, fails ConfigValue (a quote would reach the Caddyfile).
        $this->submit('general', 'Save', ['host_general[acmeEmail]' => "o'ps@example.com"]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ops@example.com', $this->host()->acmeEmail());

        $this->submit('general', 'Save', ['host_general[acmeEmail]' => '']);
        self::assertResponseRedirects('/admin/host?tab=general');
        self::assertNull($this->host()->acmeEmail());
    }

    public function testGitIdentityIsSavedAndValidated(): void
    {
        $this->submit('git', 'Save', ['host_git_identity[name]' => 'Fleet Bot', 'host_git_identity[email]' => 'bot@example.com']);
        self::assertResponseRedirects('/admin/host?tab=git');
        self::assertSame(['Fleet Bot', 'bot@example.com'], [$this->host()->gitUserName(), $this->host()->gitUserEmail()]);
        $crawler = $this->client->followRedirect();
        self::assertSame('Fleet Bot', $crawler->filter('input[name="host_git_identity[name]"]')->attr('value'));

        foreach ([
            ['Fleet <Bot>', 'bot@example.com'],
            ['Fleet Bot', 'not an address'],
            ['Fleet Bot', ''],
            ['', 'bot@example.com'],
            [str_repeat('n', 101), 'bot@example.com'],
        ] as [$name, $email]) {
            $this->submit('git', 'Save', ['host_git_identity[name]' => $name, 'host_git_identity[email]' => $email]);
            self::assertResponseStatusCodeSame(422, $name.' / '.$email);
            self::assertSame(['Fleet Bot', 'bot@example.com'], [$this->host()->gitUserName(), $this->host()->gitUserEmail()], 'a refused value changes nothing');
        }

        $this->submit('git', 'Save', ['host_git_identity[name]' => '', 'host_git_identity[email]' => '']);
        self::assertResponseRedirects('/admin/host?tab=git');
        self::assertNull($this->host()->gitUserName());
    }

    public function testClaudeTokenIsStoredWriteOnlyAndNeverEchoed(): void
    {
        $crawler = $this->open('/admin/host?tab=claude');
        self::assertSame('Not set', trim($crawler->filter('[data-testid=host-claude-status]')->text()));

        $this->submit('claude', 'Store token', ['host_claude_token[token]' => '  '.self::TOKEN.' ']);
        self::assertResponseRedirects('/admin/host?tab=claude');
        self::assertStringNotContainsString(self::TOKEN, $this->body());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Claude token stored.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertStringNotContainsString(self::TOKEN, $this->body());

        self::assertSame(self::TOKEN, $this->secretStore()->getFleetWide('claude_oauth_token'), 'the secret the wizard uses');
        $row = $this->em()->getConnection()->fetchAssociative('SELECT scope, scope_id, name, ciphertext FROM secret');
        self::assertIsArray($row);
        self::assertSame(['fleet', '', 'claude_oauth_token'], [$row['scope'], $row['scope_id'], $row['name']]);
        self::assertStringNotContainsString('sk-ant', (string) $row['ciphertext']);

        $crawler = $this->open('/admin/host?tab=claude');
        $status = $crawler->filter('[data-testid=host-claude-status]')->text();
        self::assertStringContainsString('Set', $status);
        self::assertMatchesRegularExpression('/Last rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/', $status);
        self::assertSame('', (string) $crawler->filter('input[type=password]')->attr('value'));
        self::assertStringNotContainsString(self::TOKEN, $this->body());
        self::assertStringNotContainsString($this->tail(self::TOKEN), $this->body());
    }

    public function testTheWorkspaceChecksShowPresentOrMissingWithoutValuesOnTheGitAndClaudeTabs(): void
    {
        foreach (['git', 'claude'] as $tab) {
            $crawler = $this->open('/admin/host?tab='.$tab);
            self::assertCount(1, $crawler->filter('[data-testid=host-workspace-checks]'), $tab);
            foreach (['push-key', 'git-identity', 'claude-token'] as $check) {
                self::assertStringContainsString('Missing', $crawler->filter('[data-testid=host-check-'.$check.']')->text(), $tab.' '.$check);
            }
        }

        $paths = $this->paths();
        @mkdir($paths->sshDir(), 0o700, true);
        file_put_contents($paths->sshPrivateKey(SshKeyRole::Push), 'PRIVATE-KEY-BODY-unique');
        file_put_contents($paths->sshPublicKey(SshKeyRole::Push), 'ssh-ed25519 AAAAunique push');
        $this->submit('git', 'Save', ['host_git_identity[name]' => 'Fleet Bot', 'host_git_identity[email]' => 'bot@fleet.example.com']);
        $this->submit('claude', 'Store token', ['host_claude_token[token]' => self::TOKEN]);

        foreach (['git', 'claude'] as $tab) {
            $crawler = $this->open('/admin/host?tab='.$tab);
            foreach (['push-key', 'git-identity', 'claude-token'] as $check) {
                self::assertStringContainsString('Present', $crawler->filter('[data-testid=host-check-'.$check.']')->text(), $tab.' '.$check);
            }
            foreach (['PRIVATE-KEY-BODY-unique', 'AAAAunique', self::TOKEN, 'bot@fleet.example.com'] as $value) {
                self::assertStringNotContainsString($value, $crawler->filter('[data-testid=host-workspace-checks]')->html(), $tab);
            }
        }
    }

    public function testTheClaudeTabNamesTheRotationCommandAndRecordsTheRotationInTheAuditLog(): void
    {
        $crawler = $this->open('/admin/host?tab=claude');
        self::assertStringContainsString('sudo fleet claude:token:set', $crawler->filter('[data-testid=host-claude-command]')->text());

        $this->submit('claude', 'Store token', ['host_claude_token[token]' => self::TOKEN]);

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT action, subject FROM audit_entry');
        self::assertSame([['action' => 'claude.token.rotate', 'subject' => 'fleet/claude_oauth_token']], $rows);
        self::assertStringNotContainsString(self::TOKEN, json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function testBadTokensAreRefusedWithoutEchoAndKeepTheStoredOne(): void
    {
        $this->submit('claude', 'Store token', ['host_claude_token[token]' => self::TOKEN]);

        foreach (['', 'two words in a token '.self::TOKEN, "line\nbreak-".self::TOKEN, str_repeat('a', 513)] as $bad) {
            $this->submit('claude', 'Replace token', ['host_claude_token[token]' => $bad]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringNotContainsString(self::TOKEN, $this->body());
            self::assertStringNotContainsString('two words', $this->body());
            self::assertSame(self::TOKEN, $this->secretStore()->getFleetWide('claude_oauth_token'));
        }
    }

    public function testAStorageFailureLeaksNothingToTheResponseOrTheLog(): void
    {
        $token = 'sk-ant-oat01-failure-path-'.bin2hex(random_bytes(8));
        $this->secretStore()->putFleetWide('smtp_password', 'seed', 'test'); // creates the key
        chmod($this->paths()->secretKey(), 0o644); // SecretKeyFile refuses a key readable by group/other

        $this->submit('claude', 'Store token', ['host_claude_token[token]' => $token]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('could not be stored', $this->body());
        self::assertStringNotContainsString($token, $this->body());
        self::assertStringNotContainsString($token, $this->logText());
        self::assertStringContainsString('The Claude token could not be stored.', $this->logText());
    }

    public function testCrossSiteSubmissionsAreRejected(): void
    {
        $this->as('admin', 'cross-site');
        $this->client->request('POST', '/admin/host/claude', ['host_claude_token' => ['token' => self::TOKEN, 'save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->secretStore()->hasFleetWide('claude_oauth_token'));
        self::assertStringNotContainsString(self::TOKEN, $this->body());

        $this->client->request('POST', '/admin/host/general', ['host_general' => ['acmeEmail' => 'x@example.com', 'save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/host/git', ['host_git_identity' => ['name' => 'X', 'email' => 'x@example.com', 'save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/host/notifications', ['host_notification' => ['smtpHost' => 'smtp.example.com', 'save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/host/backups/retention', ['host_backup_retention' => ['days' => '3', 'save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/host/uploads', ['host_upload_limit' => ['megabytes' => '1', 'save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/host/network', ['host_port_mirror' => ['save' => '', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->host()->portMirror());
        $this->client->request('POST', '/admin/host/backups', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(422);
        // A missing or wrong token fails the same way, even same-origin.
        $this->as('admin');
        $this->client->request('POST', '/admin/host/backups', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/host/backups');
        self::assertResponseStatusCodeSame(422);

        $host = $this->host();
        self::assertNull($host->acmeEmail());
        self::assertNull($host->gitUserName());
        self::assertNull($host->smtpHost());
        self::assertSame(14, $host->backupRetentionDays());
        self::assertSame(HostSettings::DEFAULT_ASSET_UPLOAD_MAX_BYTES, $host->assetUploadMaxBytes());
        self::assertSame([], self::getContainer()->get(BackupService::class)->list());
    }

    public function testNotificationSettingsAndTheWriteOnlySmtpPassword(): void
    {
        $this->submit('notifications', 'Save', [
            'host_notification[smtpHost]' => 'smtp.example.com',
            'host_notification[smtpPort]' => '587',
            'host_notification[smtpUser]' => 'fleet',
            'host_notification[smtpPassword]' => self::SMTP_PASSWORD,
            'host_notification[mailFrom]' => 'fleet@example.com',
            'host_notification[mailTo]' => 'ops@example.com',
        ]);
        self::assertResponseRedirects('/admin/host?tab=notifications');
        $host = $this->host();
        self::assertSame(['smtp.example.com', 587, 'fleet', 'fleet@example.com', 'ops@example.com'], [$host->smtpHost(), $host->smtpPort(), $host->smtpUser(), $host->mailFrom(), $host->mailTo()]);
        self::assertSame(self::SMTP_PASSWORD, $this->secretStore()->getFleetWide('smtp_password'));
        self::assertStringNotContainsString(self::SMTP_PASSWORD, $this->body());

        $crawler = $this->client->followRedirect();
        self::assertStringNotContainsString(self::SMTP_PASSWORD, $this->body());
        self::assertStringContainsString('Set', $crawler->filter('[data-testid=host-smtp-status]')->text());
        self::assertSame('smtp.example.com', $crawler->filter('input[name="host_notification[smtpHost]"]')->attr('value'));
        self::assertSame('587', $crawler->filter('input[name="host_notification[smtpPort]"]')->attr('value'));
        self::assertSame('', (string) $crawler->filter('input[type=password]')->attr('value'));

        // A blank password keeps the stored one.
        $this->submit('notifications', 'Save', ['host_notification[smtpUser]' => 'fleet2']);
        self::assertResponseRedirects();
        self::assertSame('fleet2', $this->host()->smtpUser());
        self::assertSame(self::SMTP_PASSWORD, $this->secretStore()->getFleetWide('smtp_password'));

        // Typing a password and asking to remove it is contradictory.
        $crawler = $this->open('/admin/host?tab=notifications');
        $form = $crawler->selectButton('Save')->form(['host_notification[smtpPassword]' => 'another-password-1']);
        $this->tick($form, 'host_notification[clearPassword]');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('another-password-1', $this->body());
        self::assertSame(self::SMTP_PASSWORD, $this->secretStore()->getFleetWide('smtp_password'));

        // The checkbox removes it.
        $crawler = $this->open('/admin/host?tab=notifications');
        $form = $crawler->selectButton('Save')->form();
        $this->tick($form, 'host_notification[clearPassword]');
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertFalse($this->secretStore()->hasFleetWide('smtp_password'));
        $crawler = $this->open('/admin/host?tab=notifications');
        self::assertStringContainsString('Not set', $crawler->filter('[data-testid=host-smtp-status]')->text());
        self::assertCount(0, $crawler->filter('input[type=checkbox]'));
    }

    public function testInvalidNotificationValuesChangeNothing(): void
    {
        foreach ([
            ['host_notification[smtpHost]' => 'smtp.example.com/path'],
            ['host_notification[smtpHost]' => 'smtp example.com'],
            ['host_notification[smtpPort]' => '0'],
            ['host_notification[smtpPort]' => '70000'],
            ['host_notification[mailTo]' => 'not-an-address'],
            ['host_notification[mailFrom]' => 'a b@example.com'],
            ['host_notification[smtpPassword]' => "bad\tpass"],
        ] as $values) {
            $this->submit('notifications', 'Save', $values);
            self::assertResponseStatusCodeSame(422, json_encode($values, \JSON_THROW_ON_ERROR));
        }
        self::assertNull($this->host()->smtpHost());
        self::assertNull($this->host()->smtpPort());
        self::assertFalse($this->secretStore()->hasFleetWide('smtp_password'));
        self::assertStringNotContainsString("bad\tpass", $this->body(), 'a refused password is not echoed');
    }

    public function testBackupNowListsAndDownloads(): void
    {
        $crawler = $this->open('/admin/host?tab=backups');
        self::assertCount(1, $crawler->filter('[data-testid=backups-empty]'));

        $form = $crawler->filter('[data-testid=backup-now-form]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/host?tab=backups');
        $crawler = $this->client->followRedirect();
        self::assertMatchesRegularExpression('/Backup written: fleet-\d{8}-\d{6}\.sqlite \(\d+ KiB\)/', $crawler->filter('[data-testid=flashes]')->text());

        $rows = $crawler->filter('[data-testid=backup-row]');
        self::assertCount(1, $rows);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}\s+\d+ KiB/', trim(preg_replace('/\s+/', ' ', $rows->text()) ?? ''));
        $href = (string) $rows->filter('a')->attr('href');
        self::assertMatchesRegularExpression('#^/admin/host/backups/fleet-\d{8}-\d{6}\.sqlite$#', $href);

        $this->client->request('GET', $href);
        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('attachment; filename=fleet-', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('application/vnd.sqlite3', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('SQLite format 3', (string) file_get_contents($response->getFile()->getPathname()));
    }

    public function testBackupNowOverTheLimitFlashesTheTooLargeMessage(): void
    {
        // A sparse file: it reports 257 MiB (over INTERACTIVE_MAX_BYTES) without using the disk.
        $this->paths()->ensureLayout();
        $handle = fopen($this->paths()->queueDb(), 'w');
        self::assertIsResource($handle);
        ftruncate($handle, BackupService::INTERACTIVE_MAX_BYTES + 1024 * 1024);
        fclose($handle);

        $crawler = $this->open('/admin/host?tab=backups');
        $this->client->submit($crawler->filter('[data-testid=backup-now-form]')->form());
        self::assertResponseRedirects('/admin/host?tab=backups');
        $crawler = $this->client->followRedirect();

        $flashes = $crawler->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('too large to back up from this page', $flashes);
        self::assertStringContainsString('fleet fleet:backup:create', $flashes);
        self::assertSame([], self::getContainer()->get(BackupService::class)->list(), 'nothing is written');
    }

    public function testGitTabWarnsAboutAStoredIdentityThatNoLongerPasses(): void
    {
        $repository = new HostSettingsRepository($this->em());
        $host = $repository->get() ?? self::fail('No host row.');
        $host->changeGitIdentity('Fleet Bot', 'bot@localhost');
        $repository->save($host);

        $crawler = $this->open('/admin/host?tab=git');
        self::assertCount(1, $crawler->filter('[data-testid=host-git-stored-invalid]'));

        $host->changeGitIdentity('Fleet Bot', 'bot@example.com');
        $repository->save($host);
        $crawler = $this->open('/admin/host?tab=git');
        self::assertCount(0, $crawler->filter('[data-testid=host-git-stored-invalid]'));
    }

    public function testDownloadRefusesEverythingButAFleetBackup(): void
    {
        $service = self::getContainer()->get(BackupService::class);
        self::assertInstanceOf(BackupService::class, $service);
        $service->create();
        $existing = $service->list(BackupKind::Fleet)[0]->name;
        // A queue backup exists on disk and is a real backup, but the page only serves the fleet database.
        file_put_contents($this->paths()->backupsDir().'/queue-20260101-000000.sqlite', 'x');

        foreach ([
            'queue-20260101-000000.sqlite',
            '..%2Fkeys%2Fsecret.key',
            '%2e%2e%2fkeys%2fsecret.key',
            '..%5Ckeys%5Csecret.key',
            'fleet-20260101-000000.sqlite',
            $existing.'%00',
            $existing.'.bak',
            '.'.$existing,
            'retention',
            'fleet.sqlite',
        ] as $name) {
            $this->client->request('GET', '/admin/host/backups/'.$name);
            self::assertResponseStatusCodeSame(404, $name);
        }
        $this->client->request('GET', '/admin/host/backups/'.$existing);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRetentionIsSavedAndBounded(): void
    {
        $crawler = $this->open('/admin/host?tab=backups');
        self::assertSame('14', $crawler->filter('input[name="host_backup_retention[days]"]')->attr('value'));

        $this->submit('backups', 'Save', ['host_backup_retention[days]' => '30']);
        self::assertResponseRedirects('/admin/host?tab=backups');
        self::assertSame(30, $this->host()->backupRetentionDays());

        foreach (['0', '-1', '3651', 'abc', ''] as $bad) {
            $this->submit('backups', 'Save', ['host_backup_retention[days]' => $bad]);
            self::assertResponseStatusCodeSame(422, $bad);
            self::assertSame(30, $this->host()->backupRetentionDays());
        }
        $this->submit('backups', 'Save', ['host_backup_retention[days]' => '3650']);
        self::assertResponseRedirects();
        self::assertSame(3650, $this->host()->backupRetentionDays());
    }

    public function testThePortMirrorSwitchRoundTripsAndSyncsTheFiles(): void
    {
        $crawler = $this->open('/admin/host?tab=network');
        self::assertTrue($this->host()->portMirror(), 'on by default');
        self::assertCount(1, $crawler->filter('input[name="host_port_mirror[enabled]"][checked]'));

        // The mirror files go to the test's own "fleet user home" (never a real ~/.ddev).
        $traefik = self::getContainer()->get(\App\Fleet\PortMirror\TraefikDirectory::class);
        self::assertInstanceOf(\App\Fleet\PortMirror\TraefikDirectory::class, $traefik);
        mkdir($traefik->dir(), 0o755, true);
        file_put_contents(\dirname($traefik->dir()).'/global_config.yaml', "router_https_port: 8443\n");

        $this->submit('network', 'Save', ['host_port_mirror[enabled]' => false]);
        self::assertResponseRedirects('/admin/host?tab=network');
        self::assertFalse($this->host()->portMirror());
        self::assertFileDoesNotExist($traefik->staticFile());

        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('input[name="host_port_mirror[enabled]"]'));
        self::assertCount(0, $crawler->filter('input[name="host_port_mirror[enabled]"][checked]'));
        self::assertStringContainsString('The mirror is off', $crawler->filter('[data-testid=host-mirror-ports]')->text());

        $this->submit('network', 'Save', ['host_port_mirror[enabled]' => '1']);
        self::assertResponseRedirects('/admin/host?tab=network');
        self::assertTrue($this->host()->portMirror());
        self::assertFileExists($traefik->staticFile(), 'switching on writes the :443 entry at once');
        self::assertStringContainsString('fleet-mirror-443', (string) file_get_contents($traefik->staticFile()));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('443', $crawler->filter('[data-testid=host-mirror-ports]')->text());
    }

    public function testTheUploadCapIsEditedInMebibytesAndBounded(): void
    {
        $crawler = $this->open('/admin/host?tab=uploads');
        self::assertSame('2048', $crawler->filter('input[name="host_upload_limit[megabytes]"]')->attr('value'));
        self::assertSame('2.0 GiB', trim($crawler->filter('[data-testid=host-upload-limit-current]')->text()));

        $this->submit('uploads', 'Save', ['host_upload_limit[megabytes]' => '512']);
        self::assertResponseRedirects('/admin/host?tab=uploads');
        self::assertSame(512 * 1_048_576, $this->host()->assetUploadMaxBytes());

        foreach (['0', '-1', '1048577', 'abc', ''] as $bad) {
            $this->submit('uploads', 'Save', ['host_upload_limit[megabytes]' => $bad]);
            self::assertResponseStatusCodeSame(422, $bad);
            self::assertSame(512 * 1_048_576, $this->host()->assetUploadMaxBytes());
        }
        $this->submit('uploads', 'Save', ['host_upload_limit[megabytes]' => '1048576']);
        self::assertResponseRedirects();
        self::assertSame(1_048_576 * 1_048_576, $this->host()->assetUploadMaxBytes());
    }

    public function testAFailedBackupShowsAGenericMessageAndLogsTheReason(): void
    {
        $crawler = $this->open('/admin/host?tab=backups');
        $form = $crawler->filter('[data-testid=backup-now-form]')->form();
        // The live connection keeps working on the unlinked file; the backup service finds no database to copy.
        unlink($this->paths()->fleetDb());

        $this->client->submit($form);
        self::assertResponseRedirects('/admin/host?tab=backups');
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('The backup failed', $crawler->filter('[data-testid=flashes]')->text());
        self::assertStringNotContainsString($this->paths()->home(), $this->body(), 'no server path in the page');
    }

    public function testAnUninitialisedHostShowsTheCommandInsteadOfTheForms(): void
    {
        $em = $this->em();
        $repository = new HostSettingsRepository($em);
        $host = $repository->get();
        self::assertNotNull($host);
        $em->remove($host);
        $em->flush();

        $crawler = $this->open('/admin/host');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=host-not-initialised]'));
        self::assertCount(0, $crawler->filter('form[name^=host_]'));

        $this->client->request('POST', '/admin/host/git', ['host_git_identity' => ['name' => 'X', 'email' => 'x@example.com', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(409);
    }

    private function tick(Form $form, string $name): void
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->tick();
    }

    private function tail(string $secret): string
    {
        return substr($secret, 12);
    }
}
