<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether the TCP peer may assert identity headers (FLEET_TRUSTED_PROXIES: IPs and CIDRs).
 */
final readonly class TrustedProxyChecker
{
    /** @var list<string> */
    private array $entries;

    public function __construct(#[Autowire('%fleet.trusted_proxies%')] string $csv)
    {
        $entries = [];
        foreach (explode(',', $csv) as $entry) {
            $entry = trim($entry);
            if ('' !== $entry) {
                $entries[] = $entry;
            }
        }
        $this->entries = $entries;
    }

    public function isTrusted(?string $ip): bool
    {
        if (null === $ip || '' === $ip || false === filter_var($ip, \FILTER_VALIDATE_IP) || [] === $this->entries) {
            return false;
        }

        return IpUtils::checkIp($ip, $this->entries);
    }
}
