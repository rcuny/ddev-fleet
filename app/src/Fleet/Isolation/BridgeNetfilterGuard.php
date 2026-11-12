<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * FLE-299: the table can only see traffic between two containers of the same bridge when the kernel module br_netfilter is
 * loaded and net.bridge.bridge-nf-call-iptables (and -ip6tables) are 1. A stock Debian host has neither, so enforce would
 * filter nothing while looking active. The root helper loads the module and keeps both across reboots; this reads the result.
 */
final readonly class BridgeNetfilterGuard
{
    public const string DIR = '/proc/sys/net/bridge';

    public function __construct(private string $dir = self::DIR)
    {
    }

    /**
     * @return string|null the problem, or null when both settings are 1
     */
    public function problem(): ?string
    {
        foreach (['bridge-nf-call-iptables', 'bridge-nf-call-ip6tables'] as $key) {
            $value = @file_get_contents($this->dir.'/'.$key);
            if (!\is_string($value) || '1' !== trim($value)) {
                return 'br_netfilter is not active (net.bridge.'.$key.' is '.(\is_string($value) ? trim($value) : 'missing').'): the table cannot see traffic inside a bridge, so enforce would filter nothing. Turning isolation on loads it (sudo helper); after a reboot fleet-isolation.service refuses to start without it.';
            }
        }

        return null;
    }
}
