<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Token;

use App\Api\Token\ApiTokenWatch;
use App\Domain\ApiToken\ApiToken;
use PHPUnit\Framework\TestCase;

final class ApiTokenWatchTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-10 12:00:00 UTC');
    }

    private function token(string $created, string $expires, ?string $lastUsed = null, bool $revoked = false): ApiToken
    {
        $token = new ApiToken('t', '', str_repeat('a', 64), 'abcdefgh', ['read'], ['*'], [], [], 'cli', new \DateTimeImmutable($created), new \DateTimeImmutable($expires));
        if (null !== $lastUsed) {
            $token->recordUse(new \DateTimeImmutable($lastUsed), '192.0.2.1');
        }
        if ($revoked) {
            $token->revoke($this->now);
        }

        return $token;
    }

    public function testExpiringListsUsableTokensWithinFourteenDaysSoonestFirst(): void
    {
        $later = $this->token('2026-11-01', '2026-11-20');
        $sooner = $this->token('2026-11-01', '2026-11-15');
        $far = $this->token('2026-11-01', '2027-02-01');
        $expired = $this->token('2026-10-01', '2026-11-09');
        $revoked = $this->token('2026-11-01', '2026-11-12', revoked: true);

        self::assertSame([$sooner, $later], ApiTokenWatch::expiring([$later, $far, $expired, $revoked, $sooner], $this->now));
    }

    public function testUnusedMeansNoUseForSixtyDaysAndAYoungTokenIsNotUnusedYet(): void
    {
        $neverOld = $this->token('2026-08-01', '2027-01-01');
        $neverYoung = $this->token('2026-11-01', '2027-01-01');
        $staleUse = $this->token('2026-06-01', '2027-01-01', '2026-08-15');
        $recentUse = $this->token('2026-06-01', '2027-01-01', '2026-11-09');
        $revoked = $this->token('2026-06-01', '2027-01-01', revoked: true);

        self::assertSame([$neverOld, $staleUse], ApiTokenWatch::unused([$neverOld, $neverYoung, $staleUse, $recentUse, $revoked], $this->now));
    }

    public function testState(): void
    {
        self::assertSame('active', ApiTokenWatch::state($this->token('2026-11-01', '2027-01-01'), $this->now));
        self::assertSame('expired', ApiTokenWatch::state($this->token('2026-10-01', '2026-11-01'), $this->now));
        self::assertSame('revoked', ApiTokenWatch::state($this->token('2026-11-01', '2027-01-01', revoked: true), $this->now));
    }
}
