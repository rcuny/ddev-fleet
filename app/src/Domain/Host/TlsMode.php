<?php

declare(strict_types=1);

namespace App\Domain\Host;

/**
 * How Caddy obtains certificates: per-name HTTP-01, or DNS-01 through the OVH API.
 */
enum TlsMode: string
{
    case Http01 = 'http01';
    case OvhDns = 'ovh_dns';
}
