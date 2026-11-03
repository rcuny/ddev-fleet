<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

/**
 * What InstanceSiteRefresher did: how many sites it rewrote and which instances failed (id => reason).
 */
final readonly class SiteRefresh
{
    /**
     * @param array<string, string> $failed
     */
    public function __construct(public int $rewritten, public array $failed = [])
    {
    }
}
