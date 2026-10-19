<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * Public and router ports of a named port. Reserved: SSH, HTTP, HTTPS, the 0.x daemon port, and the listeners the 1.x
 * host binds itself (Caddy admin 2019, Mercure hub 3000, Authelia 9091; PortNumbersTest pins the last two against
 * CaddyfileRenderer). The DDEV router's own ports (8080/8443) are refused for both: Caddy must not listen where the router does, and a
 * router port there would loop the site back into itself.
 */
final class PortNumbers
{
    public const array RESERVED = [22, 80, 443, 8765, 2019, 3000, 9091];
    public const array ROUTER_RESERVED = [8080, 8443];

    public static function publicError(mixed $port): ?string
    {
        if (!self::inRange($port)) {
            return 'Enter a port number between 1 and 65535.';
        }
        if (\in_array($port, self::RESERVED, true) || \in_array($port, self::ROUTER_RESERVED, true)) {
            return 'This port is reserved for the host.';
        }

        return null;
    }

    public static function routerError(mixed $port, mixed $public): ?string
    {
        if (!self::inRange($port)) {
            return 'Enter a port number between 1 and 65535.';
        }
        if (\in_array($port, self::RESERVED, true) || \in_array($port, self::ROUTER_RESERVED, true)) {
            return 'This port is reserved for the host.';
        }
        if ($port === $public) {
            return 'The router port must differ from the public port.';
        }

        return null;
    }

    private static function inRange(mixed $port): bool
    {
        return \is_int($port) && $port >= 1 && $port <= 65535;
    }
}
