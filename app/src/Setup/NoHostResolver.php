<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * Resolves nothing: the test environment's resolver, so the suite never depends on DNS.
 */
final readonly class NoHostResolver implements HostResolver
{
    public function resolve(string $name): array
    {
        return [];
    }
}
