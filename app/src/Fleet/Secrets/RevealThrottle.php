<?php

declare(strict_types=1);

namespace App\Fleet\Secrets;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits how often one user may reveal secret values (limiter `secret_reveal`, config/packages/rate_limiter.yaml).
 * A stolen session could otherwise read every secret in a loop; each refusal is audited by SecretService.
 */
final readonly class RevealThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.secret_reveal')]
        private RateLimiterFactoryInterface $factory,
    ) {
    }

    /**
     * Counts one reveal attempt by $actor.
     *
     * @return int|null null when the attempt is allowed, else the seconds until a retry can succeed
     */
    public function attempt(string $actor): ?int
    {
        $limit = $this->factory->create($actor)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
