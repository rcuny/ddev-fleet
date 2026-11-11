<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Api\Token\TokenFormat;
use App\Domain\ApiToken\ApiTokenRepository;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\AdminTestCase;

/** /admin/api-tokens: access, re-auth on every change, the secret shown once, rotate and revoke, the attention panel. */
final class ApiTokenPageTest extends AdminTestCase
{
    /** @var array<string, mixed> */
    private const array FORM = ['name' => 'orch', 'description' => 'the orchestrator', 'scopes' => ['read', 'lifecycle'], 'projects' => 'blog, shop', 'ip_allow' => '192.0.2.0/24', 'expires_in' => '20'];

    private function tokens(): ApiTokenRepository
    {
        $this->em();
        $repository = $this->container()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $repository);

        return $repository;
    }

    public function testOnlyAnAdminOpensThePageAndItIsInTheMenu(): void
    {
        $crawler = $this->open('/admin/api-tokens');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('API tokens', trim($crawler->filter('h1')->text()));
        self::assertGreaterThan(0, $crawler->filter('a[href="/admin/api-tokens"]')->count());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->as('member');
        $this->open('/admin/api-tokens');
        self::assertResponseStatusCodeSame(403);
    }

    private function hasMercureCookie(): bool
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (str_contains($cookie->getName(), 'mercure')) {
                return true;
            }
        }

        return false;
    }

    public function testTheAdminShellListensForTerminalWriteAlertsAndAMemberDoesNot(): void
    {
        $crawler = $this->open('/admin/api-tokens');
        $alert = $crawler->filter('[data-testid=terminal-alert]');
        self::assertCount(1, $alert);
        self::assertSame('/alerts/terminal-write', $alert->attr('data-terminal-alert-topic-value'));
        self::assertSame('/admin/audit', $alert->attr('data-terminal-alert-audit-value'));
        self::assertNotNull($alert->attr('data-turbo-permanent'));
        self::assertTrue($this->hasMercureCookie(), 'the admin page sets the cookie that authorises the alert topic');

        $this->as('member');
        $crawler = $this->open('/');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid=terminal-alert]'));
        self::assertStringNotContainsString('terminal-write', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->hasMercureCookie());
    }

    public function testCreatingNeedsAFreshReauthAndLeavesNothingBehind(): void
    {
        $this->post('/admin/api-tokens/create', self::FORM);

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/reauth', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->tokens()->all());
    }

    public function testCreateShowsTheSecretOnceAndStoresOnlyItsHash(): void
    {
        $this->confirmPassword();

        $crawler = $this->post('/admin/api-tokens/create', self::FORM);

        self::assertResponseStatusCodeSame(200);
        $secret = $crawler->filter('[data-testid=api-token-secret-value]')->attr('value');
        self::assertTrue(TokenFormat::isWellFormed((string) $secret));
        $all = $this->tokens()->all();
        self::assertCount(1, $all);
        self::assertSame(TokenFormat::hash((string) $secret), $all[0]->tokenHash());
        self::assertSame(['blog', 'shop'], $all[0]->projects());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $again = $this->open('/admin/api-tokens');
        self::assertStringNotContainsString((string) $secret, $this->body());
        self::assertCount(0, $again->filter('[data-testid=api-token-secret]'));
        self::assertCount(1, $again->filter('[data-testid=api-token-row]'));
        $audit = $this->container()->get(AuditLog::class)->latest(5, AuditLog::API_TOKEN_CREATE);
        self::assertStringNotContainsString((string) $secret, $audit[0]->subject());
    }

    public function testThePolicyRefusalIsShownAndNothingIsCreated(): void
    {
        $this->confirmPassword();

        $this->post('/admin/api-tokens/create', ['scopes' => ['terminal:write']] + self::FORM + ['x' => 1]);
        $this->post('/admin/api-tokens/create', ['scopes' => ['terminal:write'], 'ip_allow' => ''] + self::FORM);

        self::assertSame([], $this->tokens()->all());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('IP allow-list', $crawler->text());
    }

    public function testRevokeAndRotateNeedReauthAndWork(): void
    {
        $this->confirmPassword();
        $this->post('/admin/api-tokens/create', self::FORM);
        $this->confirmPassword();

        $this->post('/admin/api-tokens/1/rotate', ['grace' => '0']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, \count($this->tokens()->all()));
        self::assertTrue($this->tokens()->find(1)?->isRevoked());

        $this->post('/admin/api-tokens/2/revoke', []);
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/reauth', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFalse($this->tokens()->find(2)?->isRevoked());

        $this->confirmPassword();
        $this->post('/admin/api-tokens/2/revoke', []);
        self::assertResponseRedirects('/admin/api-tokens', 303);
        self::assertTrue($this->tokens()->find(2)?->isRevoked());

        $this->confirmPassword();
        $this->post('/admin/api-tokens/1/rotate', []);
        self::assertResponseRedirects('/admin/api-tokens', 303);
        self::assertCount(2, $this->tokens()->all());
    }

    public function testRotatingATokenAlreadyInItsGracePeriodIsRefused(): void
    {
        $this->confirmPassword();
        $this->post('/admin/api-tokens/create', self::FORM);
        $this->confirmPassword();
        $this->post('/admin/api-tokens/1/rotate', ['grace' => '600', 'expires_in' => '7']);
        self::assertResponseStatusCodeSame(200);
        $successor = $this->tokens()->find(2);
        self::assertNotNull($successor);
        self::assertLessThan(new \DateTimeImmutable('+8 days'), $successor->expiresAt());
        self::assertGreaterThan(new \DateTimeImmutable('+6 days'), $successor->expiresAt());

        $this->confirmPassword();
        $this->post('/admin/api-tokens/1/rotate', ['grace' => '600']);
        self::assertResponseRedirects('/admin/api-tokens', 303);
        self::assertCount(2, $this->tokens()->all());
        self::assertStringContainsString('already rotated', $this->client->followRedirect()->text());
    }

    public function testEveryChangeRefusesAnInvalidCsrfToken(): void
    {
        $this->confirmPassword();
        $this->post('/admin/api-tokens/create', self::FORM);
        $this->confirmPassword();

        foreach (['/admin/api-tokens/create' => self::FORM, '/admin/api-tokens/1/rotate' => ['grace' => '0'], '/admin/api-tokens/1/revoke' => []] as $path => $form) {
            $this->post($path, ['_token' => 'forged'] + $form);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertCount(1, $this->tokens()->all());
        self::assertFalse($this->tokens()->find(1)?->isRevoked());
    }

    public function testAnUnknownTokenIs404AndAMemberCannotChangeAnything(): void
    {
        $this->confirmPassword();
        $this->post('/admin/api-tokens/42/revoke', []);
        self::assertResponseStatusCodeSame(404);

        $this->as('member');
        $this->post('/admin/api-tokens/create', self::FORM);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAttentionPanelListsExpiringTokens(): void
    {
        $this->confirmPassword();
        $this->post('/admin/api-tokens/create', ['expires_in' => '5'] + self::FORM);

        $crawler = $this->open('/admin/api-tokens');

        self::assertCount(1, $crawler->filter('[data-testid=api-token-expiring] li'));
        self::assertCount(0, $crawler->filter('[data-testid=api-token-unused]'));
    }

    public function testTheAttentionPanelCountsRecentTerminalInput(): void
    {
        $crawler = $this->open('/admin/api-tokens');
        self::assertCount(0, $crawler->filter('[data-testid=api-token-terminal-writes]'));

        $calls = self::getContainer()->get(\App\Domain\ApiCall\ApiCallRepository::class);
        $calls->save(new \App\Domain\ApiCall\ApiCall(1, 'token:1:orch', 'POST terminal', 'blog--main', 'tty2', 'claude', '192.0.2.1', 5, hash('sha256', 'hello'), 'hello', true, new \DateTimeImmutable('-1 hour')));
        $calls->save(new \App\Domain\ApiCall\ApiCall(1, 'token:1:orch', 'POST terminal', 'blog--main', 'tty2', 'claude', '192.0.2.1', 5, hash('sha256', 'old'), 'old', true, new \DateTimeImmutable('-3 days')));

        $crawler = $this->open('/admin/api-tokens');

        self::assertCount(1, $crawler->filter('[data-testid=api-token-terminal-writes]'));
        self::assertStringContainsString('1 call in the last 24 hours', $crawler->filter('[data-testid=api-token-terminal-writes]')->text());
        self::assertStringNotContainsString('hello', $crawler->filter('[data-testid=api-token-terminal-writes]')->text(), 'the text stays in the audit view');
    }
}
