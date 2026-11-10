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
}
