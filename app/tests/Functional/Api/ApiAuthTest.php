<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\IssuedToken;
use App\Api\Token\TokenFormat;
use App\Domain\ApiToken\ApiScope;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SR1 (FLE-273): the API firewall is a separate door. Auth-bypass tests: forged Remote-User, expired, revoked and typo
 * tokens, a token in the query string, a token on the UI, and the failure throttle.
 */
final class ApiAuthTest extends AdminTestCase
{
    private const string SELF = '/api/v1/tokens/self';

    /**
     * @param list<ApiScope> $scopes
     * @param list<string>   $projects
     * @param list<string>   $ipAllow
     */
    private function issue(array $scopes = [ApiScope::Read], array $projects = ['*'], array $ipAllow = [], string $name = 'orchestrator'): IssuedToken
    {
        $issuer = self::getContainer()->get(ApiTokenIssuer::class);
        self::assertInstanceOf(ApiTokenIssuer::class, $issuer);

        return $issuer->issue($name, 'test token', $scopes, $projects, [], $ipAllow, new \DateTimeImmutable('+30 days'), 'cli');
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, ?string $bearer, array $server = [], ?string $from = null): array
    {
        $headers = ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none', 'REMOTE_ADDR' => $from ?? $this->clientIp] + $server;
        if (null !== $bearer) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request($method, $uri, server: $headers);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function expire(IssuedToken $issued): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE api_token SET expires_at = ? WHERE id = ?',
            ['2020-01-01 00:00:00', $issued->token->id()],
        );
        $this->em()->clear();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertUnauthorized(array $body): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('Bearer realm="fleet"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', $body['code']);
        self::assertSame(401, $body['status']);
        self::assertNotEmpty($body['request_id']);
    }

    public function testAValidTokenReadsItsOwnDescription(): void
    {
        $issued = $this->issue([ApiScope::Read, ApiScope::Deploy], ['blog'], ['10.0.0.0/8']);

        $body = $this->api('GET', self::SELF, $issued->secret);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('orchestrator', $body['name']);
        self::assertSame(['read', 'deploy'], $body['scopes']);
        self::assertSame(['blog'], $body['projects']);
        self::assertSame(['10.0.0.0/8'], $body['ip_allow']);
        self::assertGreaterThan(0, $body['expires_in_seconds']);
        self::assertSame(TokenFormat::hint($issued->secret), $body['prefix_hint']);
        // Nothing secret comes back, hash included.
        self::assertStringNotContainsString($issued->secret, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString($issued->token->tokenHash(), (string) $this->client->getResponse()->getContent());
        self::assertNotEmpty($this->client->getResponse()->headers->get('X-Request-Id'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheTokenIsStoredAsAHashOnly(): void
    {
        $issued = $this->issue();

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM api_token');

        self::assertCount(1, $rows);
        self::assertSame(TokenFormat::hash($issued->secret), $rows[0]['token_hash']);
        self::assertStringNotContainsString($issued->secret, json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function testNoTokenIsAProblemJson401(): void
    {
        $this->assertUnauthorized($this->api('GET', self::SELF, null));
    }

    /**
     * The headline bypass: the Authelia identity header, from an address the app trusts as the proxy, on a path that
     * skipped the login gate. It must not be an identity on the API, whatever it says.
     */
    public function testAForgedRemoteUserHeaderIsNotAnIdentity(): void
    {
        $body = $this->api('GET', self::SELF, null, [
            'HTTP_REMOTE_USER' => 'admin',
            'HTTP_REMOTE_GROUPS' => 'fleet-admins',
            'HTTP_REMOTE_EMAIL' => 'admin@example.com',
            'HTTP_REMOTE_NAME' => 'Admin',
            'HTTP_X_FLEET_STAND_IN_ROLE' => 'admin',
        ], '127.0.0.1');

        $this->assertUnauthorized($body);
    }

    public function testAForgedRemoteUserHeaderDoesNotChangeWhoTheTokenIs(): void
    {
        $issued = $this->issue();

        $body = $this->api('GET', self::SELF, $issued->secret, ['HTTP_REMOTE_USER' => 'admin', 'HTTP_REMOTE_GROUPS' => 'fleet-admins'], '127.0.0.1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('orchestrator', $body['name']);
        self::assertSame($issued->token->id(), $body['id']);
    }

    public function testTheForgedHeaderAttemptLeavesAnAuditTrailWithoutAnyHeaderValue(): void
    {
        $this->api('GET', self::SELF, null, ['HTTP_REMOTE_USER' => 'mallory-the-admin'], '127.0.0.1');

        $entries = self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_DENIED);
        self::assertCount(1, $entries);
        self::assertSame('api', $entries[0]->actor());
        self::assertStringContainsString('auth:no_credentials', $entries[0]->subject());
        self::assertStringContainsString('remote-user-header-ignored', $entries[0]->subject());
        self::assertStringNotContainsString('mallory', $entries[0]->subject());
    }

    public function testAnExpiredTokenIsRefusedLikeAnUnknownOne(): void
    {
        $expired = $this->issue();
        $this->expire($expired);
        $unknown = TokenFormat::generate();

        $a = $this->api('GET', self::SELF, $expired->secret);
        $this->assertUnauthorized($a);
        $b = $this->api('GET', self::SELF, $unknown);
        $this->assertUnauthorized($b);

        unset($a['request_id'], $b['request_id']);
        self::assertSame($a, $b, 'The response must not say why.');
    }

    public function testARevokedTokenIsRefusedAtOnce(): void
    {
        $issued = $this->issue();
        $this->api('GET', self::SELF, $issued->secret);
        self::assertResponseStatusCodeSame(200);

        $stored = $this->em()->find($issued->token::class, $issued->token->id());
        self::assertNotNull($stored);
        self::getContainer()->get(ApiTokenIssuer::class)->revoke($stored, 'cli');

        $this->assertUnauthorized($this->api('GET', self::SELF, $issued->secret));
    }

    /**
     * @return iterable<string, array{callable(string): string}>
     */
    public static function typos(): iterable
    {
        yield 'last character changed' => [static fn (string $t): string => substr($t, 0, -1).('a' === substr($t, -1) ? 'b' : 'a')];
        yield 'one character of the secret changed' => [static fn (string $t): string => substr($t, 0, 12).('x' === $t[12] ? 'y' : 'x').substr($t, 13)];
        yield 'truncated' => [static fn (string $t): string => substr($t, 0, -3)];
        yield 'extra character' => [static fn (string $t): string => $t.'A'];
        yield 'wrong prefix' => [static fn (string $t): string => 'ghp_'.substr($t, 4)];
        yield 'upper-cased prefix' => [static fn (string $t): string => strtoupper(substr($t, 0, 8)).substr($t, 8)];
        yield 'trailing whitespace' => [static fn (string $t): string => $t.' '];
        yield 'oversized' => [static fn (string $t): string => $t.str_repeat('A', 5000)];
        yield 'empty' => [static fn (string $t): string => ''];
    }

    /**
     * @param callable(string): string $mangle
     */
    #[DataProvider('typos')]
    public function testATypoOfAValidTokenIsRefused(callable $mangle): void
    {
        $issued = $this->issue();

        $this->assertUnauthorized($this->api('GET', self::SELF, $mangle($issued->secret)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queryParameters(): iterable
    {
        foreach (['access_token', 'token', 'api_key', 'bearer', 'flt'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('queryParameters')]
    public function testATokenInTheQueryStringIsNeverAccepted(string $name): void
    {
        $issued = $this->issue();

        $this->assertUnauthorized($this->api('GET', self::SELF.'?'.$name.'='.$issued->secret, null));
    }

    public function testATokenInTheBodyOrAnotherHeaderIsNeverAccepted(): void
    {
        $issued = $this->issue();

        $this->client->request('POST', self::SELF, ['access_token' => $issued->secret], server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none', 'REMOTE_ADDR' => $this->clientIp]);
        self::assertResponseStatusCodeSame(401);

        foreach (['HTTP_X_API_KEY', 'HTTP_X_AUTH_TOKEN', 'HTTP_X_FLEET_TOKEN'] as $header) {
            $this->api('GET', self::SELF, null, [$header => $issued->secret]);
            self::assertResponseStatusCodeSame(401, $header);
        }
        foreach (['Basic '.base64_encode('x:'.$issued->secret), 'Token '.$issued->secret, $issued->secret] as $value) {
            $this->api('GET', self::SELF, null, ['HTTP_AUTHORIZATION' => $value]);
            self::assertResponseStatusCodeSame(401, $value);
        }
    }

    public function testATokenIsRefusedFromOutsideItsAllowList(): void
    {
        $issued = $this->issue(ipAllow: ['192.0.2.0/24']);

        $this->assertUnauthorized($this->api('GET', self::SELF, $issued->secret, from: '203.0.113.5'));
        // A forwarding header cannot move the caller into the range.
        $this->assertUnauthorized($this->api('GET', self::SELF, $issued->secret, ['HTTP_X_FORWARDED_FOR' => '192.0.2.7', 'HTTP_X_REAL_IP' => '192.0.2.7'], '203.0.113.5'));
        $this->api('GET', self::SELF, $issued->secret, from: '192.0.2.77');
        self::assertResponseStatusCodeSame(200);

        $entries = self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_DENIED);
        self::assertStringContainsString('auth:ip_refused', $entries[0]->subject());
        self::assertSame('token:'.$issued->token->id(), $entries[0]->actor());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uiPaths(): iterable
    {
        foreach (['/', '/instances', '/admin/x', '/admin/users', '/_components/anything', '/jobs/1'] as $path) {
            yield $path => [$path];
        }
    }

    /**
     * A token is not a login: the UI firewall knows nothing of bearer tokens.
     */
    #[DataProvider('uiPaths')]
    public function testATokenDoesNotOpenTheUi(string $path): void
    {
        $issued = $this->issue([ApiScope::Read], ['*'], name: 'reader');

        $this->api('GET', $path, $issued->secret);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheStandInAdminCannotUseTheApi(): void
    {
        $this->client->request('GET', self::SELF, server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin', 'REMOTE_ADDR' => $this->clientIp]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownApiPathsAnswer401WithoutATokenAndProblemJson404WithOne(): void
    {
        $issued = $this->issue();

        // An anonymous caller learns nothing about which paths or methods exist: 401 for all of them.
        $this->assertUnauthorized($this->api('GET', '/api/v2/anything', null));
        $this->assertUnauthorized($this->api('POST', self::SELF, null));
        $this->assertUnauthorized($this->api('DELETE', '/api/v1/tokens/self', null));
        $body = $this->api('GET', '/api/v1/nothing-here', $issued->secret);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $body['code']);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $body = $this->api('POST', self::SELF, $issued->secret);
        self::assertResponseStatusCodeSame(405);
        self::assertSame('method_not_allowed', $body['code']);
        self::assertSame('GET', $this->client->getResponse()->headers->get('Allow'));
    }

    public function testFailedAuthenticationsAreThrottledPerAddressAndAudited(): void
    {
        $issued = $this->issue();
        for ($i = 0; $i < 10; ++$i) {
            $this->api('GET', self::SELF, TokenFormat::generate(), from: '198.51.100.9');
            self::assertResponseStatusCodeSame(401);
        }

        // Over the limit: even the right token is turned away from that address, with a Retry-After.
        $body = $this->api('GET', self::SELF, $issued->secret, from: '198.51.100.9');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $body['code']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));

        // Another address is not affected.
        $this->api('GET', self::SELF, $issued->secret, from: '198.51.100.10');
        self::assertResponseStatusCodeSame(200);

        $entries = self::getContainer()->get(AuditLog::class)->latest(50, AuditLog::API_DENIED);
        self::assertCount(10, $entries, 'One row per counted failure, none for the throttled request.');
    }

    public function testAnApiTokenIsRateLimitedPerToken(): void
    {
        $issued = $this->issue();
        $other = $this->issue(name: 'other');

        for ($i = 0; $i < 120; ++$i) {
            $this->api('GET', self::SELF, $issued->secret);
            self::assertResponseStatusCodeSame(200);
        }
        $this->api('GET', self::SELF, $issued->secret);
        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));

        $this->api('GET', self::SELF, $other->secret);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTheLastUseIsRecordedButNotOnEveryRequest(): void
    {
        $issued = $this->issue();

        $this->api('GET', self::SELF, $issued->secret, from: '192.0.2.5');
        $first = $this->em()->getConnection()->fetchOne('SELECT last_used_at FROM api_token');
        $this->api('GET', self::SELF, $issued->secret, from: '192.0.2.5');
        $second = $this->em()->getConnection()->fetchOne('SELECT last_used_at FROM api_token');

        self::assertNotEmpty($first);
        self::assertSame($first, $second);
        self::assertSame('192.0.2.5', $this->em()->getConnection()->fetchOne('SELECT last_used_ip FROM api_token'));
    }
}
