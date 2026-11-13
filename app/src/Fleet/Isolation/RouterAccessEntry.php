<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/** FLE-326: one instance's host names and the source addresses that may ask the router for them. */
final readonly class RouterAccessEntry
{
    /**
     * @param list<string> $hosts     the instance's DNS names (primary and aliases)
     * @param list<string> $sourceIps the router gateway (Caddy), the instance's own containers, the sources of links into it
     */
    public function __construct(public string $instanceId, public array $hosts, public array $sourceIps)
    {
    }
}
