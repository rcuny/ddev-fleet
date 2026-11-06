<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Audit\AuditEntryRepository;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Fleet\Secrets\SecretStore;
use App\Security\Reauth\ReauthWindow;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The re-auth window that gates Reveal secret and the sealed Export: redirect while closed, success inside, a wrong
 * password refused and audited, the rate limit, the safe return path, and no secret or password in the audit trail.
 */
final class ReauthTest extends AdminTestCase
{
    private const string VALUE = 'ReauthLeakValue-77c1';
    private const string PASSPHRASE = 'correct horse battery';

    private function seed(): void
    {
        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));
        $store = $this->container()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $store);
        $store->putManaged('blog', 'API_KEY', self::VALUE, 'admin');
    }

    /** @return list<array{string, string, string}> */
    private function audit(): array
    {
        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->em())->latest(50),
        );
    }

    private function confirmPasswordWith(string $password): void
    {
        $this->post('/admin/reauth', ['password' => $password, 'next' => '/admin']);
    }

    public function testRevealRedirectsToReauthWhileTheWindowIsClosedAndOpensNothing(): void
    {
        $this->seed();
        $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);

        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/keys', 303);
        self::assertStringNotContainsString(self::VALUE, $this->body());
        self::assertSame(['dev-admin', 'reauth.required', 'reveal project:blog/API_KEY'], $this->audit()[0]);
        self::assertNotContains('secret.reveal', array_column($this->audit(), 1));
    }

    public function testTheFleetRevealGoesBackToTheFleetList(): void
    {
        $this->post('/admin/secrets/API_KEY/reveal', []);

        self::assertResponseRedirects('/admin/reauth?next=/admin/secrets', 303);
    }

    public function testRevealWorksInsideTheWindowAndTheAuditCarriesNoValueOrPassword(): void
    {
        $this->seed();
        $this->confirmPassword();
        $crawler = $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::VALUE, trim($crawler->filter('[data-testid=revealed-value]')->text()));
        $trail = json_encode($this->audit(), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('reauth.success', $trail);
        self::assertStringContainsString('secret.reveal', $trail);
        self::assertStringNotContainsString(self::VALUE, $trail);
        self::assertStringNotContainsString(self::REAUTH_PASSWORD, $trail);
    }

    public function testTheWindowEndsAfterFiveMinutes(): void
    {
        $this->seed();
        $this->confirmPassword();
        $session = $this->client->getRequest()->getSession();
        $session->set('fleet.reauth', ['actor' => 'dev-admin', 'until' => time() - 1]);
        $session->save();

        $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);

        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/keys', 303);
        self::assertSame(300, ReauthWindow::SECONDS);
    }

    public function testAnotherUserDoesNotInheritTheWindow(): void
    {
        $this->seed();
        $this->confirmPassword();
        $this->client->getCookieJar()->set(new Cookie('fleet_stand_in_user', 'second-admin', null, '/', 'localhost', true));

        $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);

        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/keys', 303);
    }

    public function testAWrongPasswordIsRefusedAuditedAndOpensNothing(): void
    {
        $this->seed();
        $this->createReauthUser();
        $this->confirmPasswordWith('a completely wrong password');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-testid=reauth-error]', 'The password is not right.');
        self::assertStringNotContainsString('a completely wrong password', $this->body());
        self::assertSame(['dev-admin', 'reauth.failure', 'password'], $this->audit()[0]);

        $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);
        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/keys', 303);
        self::assertStringNotContainsString('a completely wrong password', json_encode($this->audit(), \JSON_THROW_ON_ERROR));
    }

    public function testAnEmptyPasswordIsRefused(): void
    {
        $this->post('/admin/reauth', ['password' => '', 'next' => '/admin']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAttemptsAreRateLimitedPerUser(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->confirmPasswordWith('wrong password '.$i);
            self::assertResponseStatusCodeSame(422);
        }
        $this->confirmPasswordWith(self::REAUTH_PASSWORD);

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertSame(['dev-admin', 'reauth.failure', 'throttled'], $this->audit()[0]);
    }

    public function testTheReturnPathIsKeptOnlyForAnAdminPage(): void
    {
        $crawler = $this->open('/admin/reauth?next=/admin/projects/blog/keys');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('/admin/projects/blog/keys', $crawler->filter('input[name=next]')->attr('value'));

        foreach (['https://evil.example.com/admin', '//evil.example.com', '/admin/../x', '/other', 'javascript:alert(1)'] as $bad) {
            $crawler = $this->open('/admin/reauth?next='.rawurlencode($bad));
            self::assertSame('/admin', $crawler->filter('input[name=next]')->attr('value'), $bad);
        }
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testThePageIsAdminOnlyAndNeedsTheCsrfToken(): void
    {
        $this->as('member');
        $this->open('/admin/reauth');
        self::assertResponseStatusCodeSame(403);

        $this->as('admin');
        $this->client->request('POST', self::BASE.'/admin/reauth', ['password' => 'x'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);
    }

    public function testASealedExportNeedsTheWindowAndAnUnsealedOneDoesNot(): void
    {
        $this->seed();

        $this->post('/admin/projects/blog/export', []);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->post('/admin/projects/blog/export', ['passphrase' => self::PASSPHRASE]);
        self::assertResponseRedirects('/admin/reauth?next=/admin/projects/blog/transfer', 303);
        self::assertSame(['dev-admin', 'reauth.required', 'export blog'], $this->audit()[0]);
        self::assertStringNotContainsString(self::PASSPHRASE, json_encode($this->audit(), \JSON_THROW_ON_ERROR));

        $this->confirmPassword();
        $this->post('/admin/projects/blog/export', ['passphrase' => self::PASSPHRASE]);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheReauthPageShowsTheFormAndTheExportHelpNamesTheMinimumLength(): void
    {
        $crawler = $this->open('/admin/reauth');
        self::assertSame('password', $crawler->filter('[data-testid=reauth-password]')->attr('type'));
        self::assertSame('current-password', $crawler->filter('[data-testid=reauth-password]')->attr('autocomplete'));

        $this->seed();
        $page = $this->open('/admin/projects/blog/transfer');
        self::assertStringContainsString('At least 8 characters', $page->filter('#export-passphrase-help')->text());
        self::assertSame('export-passphrase-help', $page->filter('[data-testid=export-passphrase]')->attr('aria-describedby'));
    }
}
