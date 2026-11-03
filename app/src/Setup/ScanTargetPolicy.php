<?php

declare(strict_types=1);

namespace App\Setup;

use App\Fleet\Ssh\KnownHostEntry;

/**
 * Which hosts the wizard may point `ssh-keyscan` at. The scan runs from the server, so a literal loopback, link-local
 * or "this host" address would let a person with the setup token probe services that only listen locally (and the
 * cloud metadata address). Names are not resolved here: a name that resolves to such an address is accepted on purpose
 * (a self-hosted git server on a private network is a real use, and a DNS answer can change between this check and
 * the scan); the scan is token-gated and only ever prints host keys. What is refused, as a cheap extra, is a name
 * whose lookup NOW yields only loopback, link-local or unspecified addresses (refusalForAddresses()).
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

        // A trailing dot is the same name ("localhost." is localhost).
        $name = rtrim($name, '.');

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
     * @param list<string> $addresses what the name resolves to right now (empty: unknown, the scan will simply fail)
     *
     * @return string|null why the name is refused: it resolves, and only to addresses that reach this machine
     */
    public static function refusalForAddresses(array $addresses): ?string
    {
        if ([] === $addresses) {
            return null;
        }
        foreach ($addresses as $address) {
            if (!self::isLocalAddress($address)) {
                return null;
            }
        }

        return 'That name points at this machine (a loopback or link-local address).';
    }

    /**
     * @throws \InvalidArgumentException when $input is not a host name or address at all
     */
    public static function normalize(string $input): string
    {
        return KnownHostEntry::normalizeHost(trim($input));
    }

    private static function isLocalAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if (false === $packed) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is judged by its IPv4 part.
        if (16 === \strlen($packed) && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }
        if (4 === \strlen($packed)) {
            $first = \ord($packed[0]);

            return 0 === $first || 127 === $first || (169 === $first && 254 === \ord($packed[1]));
        }

        // ::, ::1 and fe80::/10
        return $packed === str_repeat("\0", 16)
            || $packed === str_repeat("\0", 15)."\x01"
            || (0xFE === \ord($packed[0]) && 0x80 === (\ord($packed[1]) & 0xC0));
    }
}
