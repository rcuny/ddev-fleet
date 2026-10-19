<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\Secrets\RevealThrottle;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A RevealThrottle with its own in-memory state, for tests that build a SecretService by hand.
 */
final class RevealThrottles
{
    public static function make(int $limit = 10): RevealThrottle
    {
        return new RevealThrottle(new RateLimiterFactory(
            ['id' => 'secret_reveal', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
    }
}
