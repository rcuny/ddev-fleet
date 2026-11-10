<?php

declare(strict_types=1);

namespace App\Api\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The address the API judges a caller by (IP allow-list, throttling, audit): the connection's REMOTE_ADDR, which
 * behind Caddy's php_fastcgi is the real client. A forwarding header is never consulted, so it cannot be forged
 * to slip past an allow-list.
 */
final class ClientAddress
{
    private function __construct()
    {
    }

    public static function of(Request $request): string
    {
        $address = $request->server->get('REMOTE_ADDR');

        return \is_string($address) && '' !== $address ? $address : 'unknown';
    }
}
