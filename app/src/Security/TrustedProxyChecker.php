<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether a request comes from the authenticating reverse proxy and may therefore assert identity headers.
 *
 * Two independent proofs, either is enough:
 *
 *  - the TCP peer is in FLEET_TRUSTED_PROXIES (IPs and CIDRs; empty in production), after IPv4-mapped IPv6
 *    addresses (::ffff:a.b.c.d) are folded back to IPv4;
 *  - the server parameter FLEET_PROXY_AUTH equals FLEET_PROXY_SECRET. Caddy sets it as a FastCGI parameter
 *    (php_fastcgi { env ... }), which a client can never do: a request header only ever reaches PHP as HTTP_*.
 *    Behind php_fastcgi REMOTE_ADDR is the real client, so this is what identifies the proxy in production.
 */
final readonly class TrustedProxyChecker
{
    /** The FastCGI parameter Caddy sets; the Caddyfile template must use exactly this name. */
    public const string PROXY_PARAM = 'FLEET_PROXY_AUTH';

    /** A shorter secret is treated as "not configured": it would be guessable, and empty must never match empty. */
    private const int MIN_SECRET_LENGTH = 32;

    /** @var list<string> */
    private array $entries;

    public function __construct(
        #[Autowire('%fleet.trusted_proxies%')]
        string $csv,
        #[Autowire('%fleet.proxy_secret%')]
        private string $secret,
    ) {
        $entries = [];
        foreach (explode(',', $csv) as $entry) {
            $entry = trim($entry);
            if ('' !== $entry) {
                $entries[] = str_contains($entry, '/') ? $entry : self::unmap($entry);
            }
        }
        $this->entries = $entries;
    }

    public function isTrustedRequest(Request $request): bool
    {
        // The raw peer address, never getClientIp(): X-Forwarded-For is attacker-controlled.
        $peer = $request->server->get('REMOTE_ADDR');
        if (\is_string($peer) && $this->isTrusted($peer)) {
            return true;
        }

        if (\strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            return false;
        }
        $given = $request->server->get(self::PROXY_PARAM);

        return \is_string($given) && hash_equals($this->secret, $given);
    }

    public function isTrusted(?string $ip): bool
    {
        if (null === $ip || '' === $ip || false === filter_var($ip, \FILTER_VALIDATE_IP) || [] === $this->entries) {
            return false;
        }

        return IpUtils::checkIp(self::unmap($ip), $this->entries);
    }

    /**
     * ::ffff:127.0.0.1 (also ::ffff:7f00:1, any case or zero-compression) -> 127.0.0.1; everything else unchanged.
     * Dual-stack sockets report IPv4 peers in the mapped form, which would not match an IPv4 entry.
     */
    private static function unmap(string $ip): string
    {
        $packed = @inet_pton($ip);
        if (false === $packed || 16 !== \strlen($packed) || !str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return $ip;
        }

        $v4 = inet_ntop(substr($packed, 12));

        return false === $v4 ? $ip : $v4;
    }
}
