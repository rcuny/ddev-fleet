<?php

declare(strict_types=1);

namespace App\Security\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Slows token guessing: failed API authentications are counted per client address (limiter `api_auth_failure`,
 * 10 per 15 minutes) and an address over the limit is refused with 429 before its token is even looked at.
 */
final readonly class ApiAuthFailureThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.api_auth_failure')]
        private RateLimiterFactoryInterface $factory,
    ) {
    }

    /** True when $address has used up its failures; does not count. */
    public function isBlocked(string $address): bool
    {
        return $this->factory->create($address)->consume(0)->getRemainingTokens() < 1;
    }

    /**
     * Counts one failure.
     *
     * @return bool whether the failure was still within the limit (so it is worth an audit row)
     */
    public function recordFailure(string $address): bool
    {
        return $this->factory->create($address)->consume()->isAccepted();
    }

    /** Seconds until $address may try again. */
    public function retryAfter(string $address): int
    {
        $limit = $this->factory->create($address)->consume(0);

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
