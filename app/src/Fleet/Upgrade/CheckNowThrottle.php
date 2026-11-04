<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Limits "Check now" on the Upgrade page to one release lookup per minute per user (limiter `upgrade_check`). */
final readonly class CheckNowThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.upgrade_check')]
        private RateLimiterFactoryInterface $factory,
    ) {
    }

    /**
     * @return int|null null when the lookup is allowed, else the seconds until a retry can succeed
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
