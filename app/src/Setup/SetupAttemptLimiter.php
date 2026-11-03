<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles guesses at the setup token, per client address (limiter `setup_token`, config/packages/rate_limiter.yaml).
 * The token has 256 bits, so this only keeps a noisy client from spamming the endpoint and the log.
 */
final readonly class SetupAttemptLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.setup_token')]
        private RateLimiterFactoryInterface $factory,
    ) {
    }

    /**
     * Counts one attempt for $clientIp; false means the client is over the limit and must be refused (429) without
     * the token being looked at.
     */
    public function attempt(string $clientIp): bool
    {
        return $this->factory->create($clientIp)->consume()->isAccepted();
    }
}
