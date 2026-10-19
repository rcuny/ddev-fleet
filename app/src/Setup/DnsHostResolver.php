<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * The system resolver (A and AAAA records). No process runs.
 */
final readonly class DnsHostResolver implements HostResolver
{
    private const int MAX_ADDRESSES = 16;

    public function resolve(string $name): array
    {
        $addresses = [];
        $v4 = @gethostbynamel($name);
        foreach (false === $v4 ? [] : $v4 as $address) {
            $addresses[] = $address;
        }
        $v6 = @dns_get_record($name.'.', \DNS_AAAA);
        foreach (false === $v6 ? [] : $v6 as $record) {
            if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return \array_slice(array_values(array_unique($addresses)), 0, self::MAX_ADDRESSES);
    }
}
