<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\Token\ApiTokenIssuer;
use App\Api\Token\InvalidApiTokenRequest;
use App\Domain\ApiToken\ApiScope;
use App\Domain\ApiToken\ApiTokenRepository;
use App\Fleet\Audit\AuditLog;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiTokenIssuerTest extends KernelTestCase
{
    use FleetDatabase;

    private ApiTokenIssuer $issuer;
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        $this->setUpFleetDatabase();
        $this->issuer = self::getContainer()->get(ApiTokenIssuer::class);
        $this->tokens = new ApiTokenRepository($this->entityManager());
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function issue(string $name = 'ci', string $ip = '192.0.2.0/24'): \App\Api\Token\IssuedToken
    {
        return $this->issuer->issue($name, 'desc', [ApiScope::Read, ApiScope::Deploy], ['blog'], ['x--*'], [$ip], new \DateTimeImmutable('+10 days'), 'alice');
    }

    public function testIssuingStoresAHashAndAuditsWithoutTheSecret(): void
    {
        $issued = $this->issue();

        self::assertNotNull($issued->token->id());
        self::assertNotSame($issued->secret, $issued->token->tokenHash());
        self::assertSame($issued->token, $this->tokens->findByHash(hash('sha256', $issued->secret)));
        $entries = self::getContainer()->get(AuditLog::class)->latest(5);
        self::assertSame(AuditLog::API_TOKEN_CREATE, $entries[0]->action());
        self::assertSame('alice', $entries[0]->actor());
        self::assertStringNotContainsString($issued->secret, $entries[0]->subject());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'upper-case name' => ['CI', '192.0.2.1'];
        yield 'name with a colon (the audit actor token:<id>:<name> must split)' => ['ci:prod', '192.0.2.1'];
        yield 'name with a space' => ['my token', '192.0.2.1'];
        yield 'empty name' => ['', '192.0.2.1'];
        yield 'long name' => [str_repeat('a', 33), '192.0.2.1'];
        yield 'bad ip' => ['ci', '999.1.1.1'];
        yield 'bad prefix length' => ['ci', '10.0.0.0/33'];
        yield 'word as ip' => ['ci', 'localhost'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidRequestsAreRefused(string $name, string $ip): void
    {
        $this->expectException(InvalidApiTokenRequest::class);

        $this->issuer->issue($name, '', [ApiScope::Read], ['blog'], [], [$ip], new \DateTimeImmutable('+1 day'), 'alice');
    }

    public function testRevokingIsAuditedAndIdempotent(): void
    {
        $issued = $this->issue();

        $this->issuer->revoke($issued->token, 'alice');
        $first = $issued->token->revokedAt();
        $this->issuer->revoke($issued->token, 'alice');

        self::assertNotNull($first);
        self::assertSame($first, $issued->token->revokedAt());
        self::assertCount(1, self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_TOKEN_REVOKE));
        self::assertFalse($issued->token->isUsable(new \DateTimeImmutable()));
    }

    public function testRotationKeepsScopesAndRestrictionsAndKillsTheOldTokenAtOnce(): void
    {
        $old = $this->issue();

        $new = $this->issuer->rotate($old->token, 'alice');

        self::assertNotSame($old->secret, $new->secret);
        self::assertSame($old->token->scopes(), $new->token->scopes());
        self::assertSame(['blog'], $new->token->projects());
        self::assertSame(['x--*'], $new->token->instanceGlobs());
        self::assertSame(['192.0.2.0/24'], $new->token->ipAllow());
        self::assertSame($old->token->id(), $new->token->rotatedFrom());
        self::assertTrue($old->token->isRevoked());
        self::assertTrue($new->token->isUsable(new \DateTimeImmutable()));
        self::assertCount(1, self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_TOKEN_ROTATE));
    }

    public function testRotationWithAGraceKeepsTheOldTokenForAtMostAnHour(): void
    {
        $old = $this->issue();

        $this->issuer->rotate($old->token, 'alice', graceSeconds: 999999);

        self::assertFalse($old->token->isRevoked());
        self::assertTrue($old->token->isUsable(new \DateTimeImmutable()));
        self::assertFalse($old->token->isUsable(new \DateTimeImmutable('+61 minutes')));
    }

    public function testAuditActorIsParsedByIdAndNameWithAColonIsRefused(): void
    {
        $issued = $this->issue('ci-prod');
        $actor = \App\Security\Api\ApiTokenUser::fromToken($issued->token)->getUserIdentifier();

        // The id is the stable part: parse "token:<id>:<name>" by id, never by splitting on every colon.
        if (1 !== preg_match('/^token:(\d+):(.+)$/', $actor, $m)) {
            self::fail('Unexpected audit actor format: '.$actor);
        }
        self::assertSame((int) $issued->token->id(), (int) $m[1]);
        self::assertSame('ci-prod', $m[2]);

        // A name with ':' (ci:prod) would make the actor ambiguous, so the issuer refuses it.
        $this->expectException(InvalidApiTokenRequest::class);
        $this->issue('ci:prod');
    }

    public function testRotationAuditsTheRevocationOfTheOldToken(): void
    {
        $old = $this->issue();

        $this->issuer->rotate($old->token, 'alice');

        $revokes = self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_TOKEN_REVOKE);
        self::assertCount(1, $revokes);
        self::assertStringContainsString('token:'.$old->token->id().':ci', $revokes[0]->subject());
        self::assertSame('alice', $revokes[0]->actor());
    }

    public function testRotationWithAGraceAuditsTheExpiryOfTheOldToken(): void
    {
        $old = $this->issue();

        $this->issuer->rotate($old->token, 'alice', graceSeconds: 600);

        $expires = self::getContainer()->get(AuditLog::class)->latest(10, AuditLog::API_TOKEN_EXPIRE);
        self::assertCount(1, $expires);
        self::assertStringContainsString('grace 600s', $expires[0]->subject());
    }

    public function testATokenAlreadyInItsGracePeriodCannotBeRotatedAgain(): void
    {
        $old = $this->issue();
        $this->issuer->rotate($old->token, 'alice', graceSeconds: 600);

        $this->expectException(InvalidApiTokenRequest::class);
        $this->expectExceptionMessage('already rotated');
        $this->issuer->rotate($old->token, 'alice');
    }

    public function testARevokedTokenCannotBeRotated(): void
    {
        $old = $this->issue();
        $this->issuer->revoke($old->token, 'alice');

        $this->expectException(InvalidApiTokenRequest::class);
        $this->expectExceptionMessage('revoked');
        $this->issuer->rotate($old->token, 'alice');
    }

    public function testAnExpiredTokenCannotBeRotatedAndLeavesNoSuccessor(): void
    {
        $old = $this->issue();
        $old->token->expireNoLaterThan(new \DateTimeImmutable('-1 minute'));
        $before = \count($this->tokens->all());

        try {
            $this->issuer->rotate($old->token, 'alice');
            self::fail('Rotating an expired token must be refused.');
        } catch (InvalidApiTokenRequest $e) {
            self::assertStringContainsString('expired', $e->getMessage());
        }
        self::assertCount($before, $this->tokens->all());
    }
}
