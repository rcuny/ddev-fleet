<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * What a host name resolves to, for ScanTargetPolicy::refusalForAddresses(). A seam so tests do not touch DNS.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when the name does not resolve
     */
    public function resolve(string $name): array;
}
