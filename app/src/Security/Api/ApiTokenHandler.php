<?php

declare(strict_types=1);

namespace App\Security\Api;

use App\Api\Http\ClientAddress;
use App\Api\Token\TokenFormat;
use App\Domain\ApiToken\ApiTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Turns a bearer token into the ApiTokenUser, or refuses it: shape and checksum first (no database lookup for noise),
 * then the hash lookup, revocation, expiry and the token's IP allow-list. Every refusal throws ApiTokenRejected, and
 * the response to the client is the same whatever the reason.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    private const int MAX_TOKEN_LENGTH = 128;
    /** A use is written at most this often, so a busy client does not turn every read into a database write. */
    private const int LAST_USED_GRANULARITY = 60;

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requests,
        private readonly ApiAuthFailureThrottle $throttle,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $request = $this->requests->getCurrentRequest();
        $address = null === $request ? 'unknown' : ClientAddress::of($request);

        if ($this->throttle->isBlocked($address)) {
            throw new ApiTokenRejected(ApiTokenRejected::THROTTLED);
        }
        if (\strlen($accessToken) > self::MAX_TOKEN_LENGTH || !TokenFormat::isWellFormed($accessToken)) {
            throw new ApiTokenRejected(ApiTokenRejected::MALFORMED);
        }

        $hash = TokenFormat::hash($accessToken);
        $token = $this->tokens->findByHash($hash);
        if (null === $token || !hash_equals($token->tokenHash(), $hash)) {
            throw new ApiTokenRejected(ApiTokenRejected::UNKNOWN);
        }

        $now = $this->clock->now();
        if ($token->isRevoked()) {
            throw new ApiTokenRejected(ApiTokenRejected::REVOKED, $token->id());
        }
        if ($token->isExpired($now)) {
            throw new ApiTokenRejected(ApiTokenRejected::EXPIRED, $token->id());
        }
        if ([] !== $token->ipAllow() && !IpUtils::checkIp($address, $token->ipAllow())) {
            throw new ApiTokenRejected(ApiTokenRejected::IP_REFUSED, $token->id());
        }

        $last = $token->lastUsedAt();
        if (null === $last || $token->lastUsedIp() !== $address || $now->getTimestamp() - $last->getTimestamp() >= self::LAST_USED_GRANULARITY) {
            $token->recordUse($now, $address);
            $this->tokens->save($token);
        }

        $user = ApiTokenUser::fromToken($token);

        return new UserBadge($user->getUserIdentifier(), static fn (): ApiTokenUser => $user);
    }
}
