<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Domain\Host\TlsMode;
use App\Fleet\Shell\CommandResult;
use App\Fleet\System\PrivilegedApply;
use App\Tests\Support\WizardTestCase;

final class FinishTest extends WizardTestCase
{
    private const string CLAUDE = 'sk-ant-oat01-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcdef';
    private const string OVH_SECRET = 'AppSecretBBBB22xyz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    /** Everything the wizard needs before Finish: an administrator and the two SSH keys. */
    private function ready(): void
    {
        $this->createAdmin();
        $this->open('/setup/ssh');
    }

    public function testSummaryShowsNoSecrets(): void
    {
        $this->ready();
        $store = $this->secretStore();
        $store->putFleetWide('claude_oauth_token', self::CLAUDE, 'test');
        $store->putFleetWide('ovh_application_secret', self::OVH_SECRET, 'test');
        $host = $this->host();

        $crawler = $this->open('/setup/finish');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('fleet.example.com', trim($crawler->filter('[data-testid=summary-domain]')->text()));
        self::assertStringContainsString('root', $crawler->filter('[data-testid=summary-admin]')->text());
        self::assertSame('Stored', trim($crawler->filter('[data-testid=summary-claude]')->text()));
        self::assertStringStartsWith('SHA256:', trim($crawler->filter('[data-testid=summary-key-deploy]')->text()));
        self::assertStringStartsWith('SHA256:', trim($crawler->filter('[data-testid=summary-key-push]')->text()));
        foreach ([self::CLAUDE, self::OVH_SECRET, self::PASSWORD, $this->token, 'PRIVATE KEY'] as $secret) {
            self::assertStringNotContainsString($secret, $this->body());
        }
        self::assertStringNotContainsString($host->setupTokenHash() ?? 'x', $this->body());
    }

    public function testFinishSuccessRendersSignInAndClosesTheWizard(): void
    {
        $this->ready();
        $this->secretStore()->putFleetWide('claude_oauth_token', self::CLAUDE, 'test');

        $this->post('/setup/finish', []);

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Setup finished');
        self::assertSame('/', $this->client->getCrawler()->filter('[data-testid=setup-sign-in]')->attr('href'));
        self::assertStringContainsString('Authelia', $this->body());
        self::assertCount(0, $this->client->getCrawler()->filter('[data-testid=setup-stepper]'));
        foreach ([self::CLAUDE, self::PASSWORD, $this->token] as $secret) {
            self::assertStringNotContainsString($secret, $this->body());
        }

        $cleared = array_values(array_filter(
            $this->client->getResponse()->headers->getCookies(),
            static fn ($cookie): bool => '__Host-fleet_setup' === $cookie->getName(),
        ));
        self::assertCount(1, $cleared);
        self::assertLessThan(time(), $cleared[0]->getExpiresTime(), 'the wizard cookie is cleared');

        self::assertTrue($this->host()->isSetupComplete());
        self::assertNull($this->host()->setupTokenHash());
        self::assertCount(1, $this->runner()->callsMatching(['sudo', '-n', '--', PrivilegedApply::HELPER, 'authelia']));
        self::assertCount(0, $this->runner()->callsMatching(['sudo', '-n', '--', PrivilegedApply::HELPER, 'caddy']), 'http01 needs no Caddy apply');
        self::assertFileExists($this->paths()->usersFile());
        self::assertStringContainsString('root', (string) file_get_contents($this->paths()->usersFile()));

        // The wizard is gone for everyone, cookie or not.
        $this->open('/setup/admin');
        self::assertResponseStatusCodeSame(404);
        $this->post('/setup/finish', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFinishInDnsModeAlsoAppliesCaddy(): void
    {
        $this->ready();
        $store = $this->secretStore();
        $store->putFleetWide('ovh_endpoint', 'ovh-eu', 'test');
        $store->putFleetWide('ovh_application_key', 'AppKeyAAAA11', 'test');
        $store->putFleetWide('ovh_application_secret', self::OVH_SECRET, 'test');
        $store->putFleetWide('ovh_consumer_key', 'ConsumerCCCC33', 'test');
        $repository = new \App\Domain\Host\HostSettingsRepository($this->em());
        $host = $repository->get() ?? self::fail('No host row.');
        $host->changeTlsMode(TlsMode::OvhDns);
        $repository->save($host);

        $this->post('/setup/finish', []);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->runner()->callsMatching(['sudo', '-n', '--', PrivilegedApply::HELPER, 'caddy']));
        foreach ([self::OVH_SECRET, 'ConsumerCCCC33', 'AppKeyAAAA11'] as $secret) {
            self::assertStringNotContainsString($secret, $this->body());
        }
    }

    public function testApplyFailureShowsErrorAndKeepsSetupOpen(): void
    {
        $this->ready();
        $this->runner()->expect(
            ['sudo', '-n', '--', PrivilegedApply::HELPER, 'authelia'],
            new CommandResult(1, '', "authelia: configuration invalid\nFLEET_PROXY_SECRET=hunter2hunter2hunter2hunter2hunter2"),
        );

        $this->post('/setup/finish', []);

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorExists('[data-testid=setup-finish-error]');
        self::assertStringContainsString('Applying "authelia" failed', $this->client->getCrawler()->filter('[data-testid=setup-finish-error]')->text());
        self::assertStringContainsString('press Finish again', $this->body());
        self::assertStringNotContainsString('hunter2', $this->body(), 'the helper output is redacted');
        self::assertFalse($this->host()->isSetupComplete());
        self::assertNotNull($this->host()->setupTokenHash());

        // Same session, same link: the retry works.
        $this->open('/setup/finish');
        self::assertResponseStatusCodeSame(200);
        $this->post('/setup/finish', []);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->host()->isSetupComplete());
    }

    public function testASecondFinishWhileOneRunsSaysSoAndDoesNotClaimNothingHappened(): void
    {
        $this->ready();
        $lock = $this->container()->get('lock.factory')->createLock('setup-finish', 60, false);
        self::assertTrue($lock->acquire(false));

        $this->post('/setup/finish', []);
        $lock->release();

        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('[data-testid=setup-finish-running]');
        self::assertStringContainsString('may be done by now', $this->body());
        self::assertStringNotContainsString('Nothing was marked complete', $this->body());
        self::assertSelectorNotExists('[data-testid=setup-finish-error]');
    }

    public function testAfterCompletionTheFinishUrlExplainsInsteadOfJustFailing(): void
    {
        $this->ready();
        $this->post('/setup/finish', []);
        self::assertResponseStatusCodeSame(200);

        // The browser that never got the result page (or double-submitted) reloads the URL.
        $this->post('/setup/finish', []);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('[data-testid=setup-complete]', 'auth.fleet.example.com');
        self::assertSame('/', $this->client->getCrawler()->filter('[data-testid=setup-complete-sign-in]')->attr('href'));
    }

    public function testFinishWithoutAdminRedirectsToAdminStep(): void
    {
        $this->open('/setup/finish');
        self::assertResponseRedirects('/setup/admin');

        $this->post('/setup/finish', []);
        self::assertResponseRedirects('/setup/admin');
        self::assertFalse($this->host()->isSetupComplete());
        self::assertSame([], $this->runner()->callsMatching(['sudo']));
    }

    public function testFinishWithoutKeysRedirectsToTheSshStep(): void
    {
        $this->createAdmin();

        $this->post('/setup/finish', []);

        self::assertResponseRedirects('/setup/ssh');
        self::assertSame([], $this->runner()->callsMatching(['sudo']));
    }

    public function testFinishNeedsTheFormToken(): void
    {
        $this->ready();

        $this->client->request('POST', self::BASE.'/setup/finish', server: $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        $this->post('/setup/finish', [], fetchSite: 'cross-site');
        self::assertResponseStatusCodeSame(403);

        self::assertFalse($this->host()->isSetupComplete());
        self::assertSame([], $this->runner()->callsMatching(['sudo']));
    }

    public function testTheFinishButtonShowsProgressWhileItRuns(): void
    {
        $this->ready();
        $crawler = $this->open('/setup/finish');

        self::assertSame('busy', $crawler->filter('[data-testid=setup-finish-form]')->attr('data-controller'));
        self::assertNotNull($crawler->filter('[data-busy-target=note]')->attr('hidden'));
    }
}
