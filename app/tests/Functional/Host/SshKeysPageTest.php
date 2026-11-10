<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Template\SshIdentities;
use App\Fleet\FleetPaths;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Projects\TemplateInput;
use App\Fleet\Projects\TemplateService;
use App\Fleet\Ssh\SshKeyService;
use App\Tests\Support\AdminTestCase;

final class SshKeysPageTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->container()->get(HostSettingsRepository::class);
        self::assertInstanceOf(HostSettingsRepository::class, $settings);
        $settings->getOrCreate('fleet.example.com', new \DateTimeImmutable());
        $keys = $this->container()->get(SshKeyService::class);
        self::assertInstanceOf(SshKeyService::class, $keys);
        $keys->ensureKeys();
    }

    private function paths(): FleetPaths
    {
        $paths = $this->container()->get(FleetPaths::class);
        self::assertInstanceOf(FleetPaths::class, $paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function audit(): array
    {
        return array_map(static fn ($e): string => $e->action().' '.$e->subject(), array_values(array_filter(
            new AuditEntryRepository($this->em())->latest(50),
            static fn ($e): bool => str_starts_with($e->action(), 'ssh.key.'),
        )));
    }

    public function testTheViewListsEveryKeyWithPurposeFingerprintPublicKeyAndCopy(): void
    {
        $crawler = $this->open('/admin/host/ssh-keys');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=host-tab-ssh]'));
        self::assertSame(['SSH keys'], $crawler->filter('[data-testid=host-tabs] a[aria-current=page]')->each(static fn ($a): string => trim($a->text())));
        self::assertSame(['deploy', 'push'], $crawler->filter('[data-testid=ssh-key-name]')->each(static fn ($n): string => trim($n->text())));
        self::assertCount(2, $crawler->filter('[data-testid=ssh-key-copy]'));
        self::assertStringStartsWith('SHA256:', trim($crawler->filter('[data-testid=ssh-key-fingerprint]')->first()->text()));
        self::assertStringStartsWith('ssh-ed25519 ', (string) $crawler->filter('[data-testid=ssh-key-public]')->first()->attr('value'));
        self::assertStringContainsString('Clones and fetches', $crawler->filter('[data-testid=ssh-key-purpose]')->first()->text());
        self::assertStringNotContainsString('PRIVATE KEY', $this->body());
        self::assertSame($crawler->filter('[data-testid=host-tab-ssh]')->count(), $this->open('/admin/host?tab=ssh')->filter('[data-testid=host-tab-ssh]')->count());
    }

    public function testMembersCannotOpenOrChangeKeys(): void
    {
        $this->as('member');
        $this->open('/admin/host/ssh-keys');
        self::assertResponseStatusCodeSame(403);
        $this->post('/admin/host/ssh-keys/create', ['name' => 'staging']);
        self::assertResponseStatusCodeSame(403);
        self::assertFileDoesNotExist($this->paths()->sshExtraKey('staging'));
    }

    public function testCreatingNeedsAFreshPasswordThenCreatesDeletesAndAudits(): void
    {
        $this->post('/admin/host/ssh-keys/create', ['name' => 'staging']);
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/admin/reauth', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('next=/admin/host/ssh-keys', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFileDoesNotExist($this->paths()->sshExtraKey('staging'));

        $this->confirmPassword();
        $this->post('/admin/host/ssh-keys/create', ['name' => 'staging']);
        self::assertResponseRedirects('/admin/host/ssh-keys', 303);
        self::assertFileExists($this->paths()->sshExtraKey('staging'));
        $page = $this->open('/admin/host/ssh-keys');
        self::assertContains('staging', $page->filter('[data-testid=ssh-key-name]')->each(static fn ($n): string => trim($n->text())));
        self::assertCount(1, $page->filter('[data-testid=ssh-key-delete]'));
        self::assertContains('ssh.key.create staging', $this->audit());

        // The window closed after the change: the delete asks again.
        $this->post('/admin/host/ssh-keys/staging/delete', []);
        self::assertStringContainsString('/admin/reauth', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFileExists($this->paths()->sshExtraKey('staging'));

        $this->confirmPassword();
        $this->post('/admin/host/ssh-keys/staging/delete', []);
        self::assertResponseRedirects('/admin/host/ssh-keys', 303);
        self::assertFileDoesNotExist($this->paths()->sshExtraKey('staging'));
        self::assertContains('ssh.key.delete staging', $this->audit());
    }

    public function testBadNamesAndATokenlessPostChangeNothing(): void
    {
        $this->confirmPassword();
        $this->post('/admin/host/ssh-keys/create', ['name' => 'push']);
        self::assertResponseRedirects('/admin/host/ssh-keys', 303);
        self::assertStringContainsString('Not created', $this->open('/admin/host/ssh-keys')->filter('[data-testid=flashes]')->text());

        $this->client->request('POST', self::BASE.'/admin/host/ssh-keys/create', ['name' => 'staging'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
        self::assertFileDoesNotExist($this->paths()->sshExtraKey('staging'));
    }

    public function testImportingGarbageSaysSoWithoutEchoingTheKey(): void
    {
        $this->confirmPassword();
        $this->post('/admin/host/ssh-keys/import', ['name' => 'vendor', 'private_key' => 'GARBAGE-MARKER-123']);

        self::assertResponseRedirects('/admin/host/ssh-keys', 303);
        $flashes = $this->open('/admin/host/ssh-keys')->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('Not imported', $flashes);
        self::assertStringNotContainsString('GARBAGE-MARKER-123', $flashes);
        self::assertFileDoesNotExist($this->paths()->sshExtraKey('vendor'));
    }

    public function testRotatingNeedsTheTickAndAFreshPassword(): void
    {
        $before = $this->fingerprint('push');
        $this->confirmPassword();

        $this->post('/admin/host/ssh-keys/push/rotate', []);
        self::assertSame($before, $this->fingerprint('push'), 'no confirmation, no change');

        $this->post('/admin/host/ssh-keys/push/rotate', ['confirm' => '1']);
        self::assertResponseRedirects('/admin/host/ssh-keys', 303);
        self::assertNotSame($before, $this->fingerprint('push'));
        self::assertContains('ssh.key.rotate push', $this->audit());
    }

    public function testAnExternalKeyIsListedAndNeverOfferedForDeletion(): void
    {
        $this->post('/admin/host/ssh-keys/nope/delete', []); // unknown + no reauth: the challenge, nothing else
        @mkdir($this->paths()->sshDir(), 0o700, true);
        $generated = new \App\Fleet\Ssh\Ed25519KeyGenerator()->generate('hand');
        file_put_contents($this->paths()->sshExtraKey('legacy'), $generated->privatePem());
        chmod($this->paths()->sshExtraKey('legacy'), 0o600);

        $page = $this->open('/admin/host/ssh-keys');

        $card = $page->filter('[data-testid=ssh-key-card][data-key=legacy]');
        self::assertCount(1, $card);
        self::assertStringContainsString($generated->fingerprint, $card->text());
        self::assertCount(1, $card->filter('[data-testid=ssh-key-external-note]'));
        self::assertCount(0, $card->filter('[data-testid=ssh-key-delete]'));
    }

    public function testTheWarningAppearsWhenATemplateIsSavedWithSharedAgentCommands(): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $templates = $this->container()->get(TemplateService::class);
        self::assertInstanceOf(TemplateService::class, $templates);
        $templates->create('blog', new TemplateInput('web'));

        $crawler = $this->open('/admin/projects/blog/templates/web');
        self::assertCount(1, $crawler->filter('[name="template[sshIdentities]"]'));
        $this->submit($crawler, 'Save', ['template[postDeploy]' => "composer install\nddev auth ssh -f /srv/fleet/ssh/push_ed25519"]);

        self::assertResponseRedirects('/admin/projects/blog/templates', 303);
        $flash = $this->open('/admin/projects/blog/templates')->filter('[data-testid=flashes]')->text();
        self::assertStringContainsString('Template saved', $flash);
        self::assertStringContainsString('post-deploy line 2', $flash);
        self::assertStringContainsString('shared ssh-agent', $flash);
        self::assertSame(['composer install', 'ddev auth ssh -f /srv/fleet/ssh/push_ed25519'], $templates->get('blog', 'web')->postDeploy(), 'saved, not refused');
        self::assertSame(SshIdentities::PushAndExtras, $templates->get('blog', 'web')->sshIdentities());
    }

    private function fingerprint(string $role): string
    {
        $this->em()->clear();

        return (string) $this->em()->getConnection()->fetchOne('SELECT fingerprint FROM ssh_key WHERE role = ?', [$role]);
    }

    public function testThePushKeyCheckRefusesAHostThatIsNotAProjectRemote(): void
    {
        $this->post('/admin/host/ssh-keys/check-push', ['host' => 'evil.example.com']);

        self::assertResponseRedirects('/admin/host/ssh-keys', 303);
        self::assertStringContainsString('registered projects', $this->open('/admin/host/ssh-keys')->filter('[data-testid=flashes]')->text());
    }
}
