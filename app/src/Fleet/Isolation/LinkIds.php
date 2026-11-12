<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * FLE-299: the names derived from one resolved link. The Docker network name is long and readable; the bridge interface
 * (IFNAMSIZ 15) is `flk-` plus 8 hex digits of the id's sha256, so the nftables rule can key on a stable, short name.
 */
final class LinkIds
{
    public const string NETWORK_PREFIX = 'fleet-link-';
    public const string LABEL = 'com.ddev-fleet.link';

    public static function id(string $sourceInstance, string $targetInstance, string $service, int $port): string
    {
        return $sourceInstance.'--'.$targetInstance.'--'.$service.'--'.$port;
    }

    public static function network(string $id): string
    {
        return self::NETWORK_PREFIX.$id;
    }

    public static function bridge(string $id): string
    {
        return 'flk-'.substr(hash('sha256', $id), 0, 8);
    }
}
