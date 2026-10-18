<?php

declare(strict_types=1);

namespace App\Setup;

use App\Fleet\Ssh\KnownHostEntry;

/**
 * Which hosts the wizard may point `ssh-keyscan` at. The scan runs from the server, so a literal loopback, link-local
 * or "this host" address would let a person with the setup token probe services that only listen locally (and the
 * cloud metadata address). Names are not resolved here: a name that resolves to such an address is accepted on purpose
 * (a self-hosted git server on a private network is a real use, and a DNS answer can change between this check and
 * the scan); the scan is token-gated and only ever prints host keys.
 */
final class ScanTargetPolicy
{
    private function __construct()
    {
    }

    /**
     * @return string|null why $host is refused, null when it may be scanned; $host must already be normalised
     *                     (KnownHostEntry::normalizeHost())
     */
    public static function refusal(string $host): ?string
    {
        $name = $host;
        if (str_starts_with($name, '[')) {
            $name = substr($name, 1, (int) strrpos($name, ']') - 1);
        }

        if ('localhost' === $name || str_ends_with($name, '.localhost')) {
            return 'That is this machine; enter the git server\'s own host name.';
        }
        if (1 !== preg_match('/^[0-9][0-9a-fx.]*$/', $name)) {
            return null;
        }

        // Only digits, dots and hex letters: an IPv4 address in some notation. Anything but plain dotted decimal
        // ("127.1", "2130706433", "0x7f.0.0.1", "0177.0.0.1") is read as loopback by the resolver, so it is refused.
        $octets = explode('.', $name);
        if (4 !== \count($octets)) {
            return 'Write an IP address as four decimal numbers, for example 203.0.113.7.';
        }
        $numbers = [];
        foreach ($octets as $octet) {
            if (1 !== preg_match('/^(0|[1-9][0-9]{0,2})$/', $octet) || (int) $octet > 255) {
                return 'Write an IP address as four decimal numbers, for example 203.0.113.7.';
            }
            $numbers[] = (int) $octet;
        }
        if (0 === $numbers[0] || 127 === $numbers[0] || (169 === $numbers[0] && 254 === $numbers[1])) {
            return 'Loopback, link-local and "this host" addresses cannot be scanned.';
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException when $input is not a host name or address at all
     */
    public static function normalize(string $input): string
    {
        return KnownHostEntry::normalizeHost(trim($input));
    }
}
