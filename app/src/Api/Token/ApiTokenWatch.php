<?php

declare(strict_types=1);

namespace App\Api\Token;

use App\Domain\ApiToken\ApiToken;

/**
 * Which usable tokens need a person's attention: those about to expire and those nobody uses (FLE-265 design, 4.4).
 * Pure: no I/O.
 */
final class ApiTokenWatch
{
    public const int EXPIRING_DAYS = 14;
    public const int UNUSED_DAYS = 60;

    /**
     * @param list<ApiToken> $tokens
     *
     * @return list<ApiToken> usable tokens that expire within EXPIRING_DAYS, soonest first
     */
    public static function expiring(array $tokens, \DateTimeImmutable $now): array
    {
        $limit = $now->modify('+'.self::EXPIRING_DAYS.' days');
        $found = array_values(array_filter($tokens, static fn (ApiToken $t): bool => $t->isUsable($now) && $t->expiresAt() <= $limit));
        usort($found, static fn (ApiToken $a, ApiToken $b): int => $a->expiresAt() <=> $b->expiresAt());

        return $found;
    }

    /**
     * @param list<ApiToken> $tokens
     *
     * @return list<ApiToken> usable tokens never used in the last UNUSED_DAYS days (a token younger than that is not "unused" yet)
     */
    public static function unused(array $tokens, \DateTimeImmutable $now): array
    {
        $limit = $now->modify('-'.self::UNUSED_DAYS.' days');

        return array_values(array_filter(
            $tokens,
            static fn (ApiToken $t): bool => $t->isUsable($now) && ($t->lastUsedAt() ?? $t->createdAt()) <= $limit,
        ));
    }

    /** "active", "revoked" or "expired". */
    public static function state(ApiToken $token, \DateTimeImmutable $now): string
    {
        return match (true) {
            $token->isRevoked() => 'revoked',
            $token->isExpired($now) => 'expired',
            default => 'active',
        };
    }
}
