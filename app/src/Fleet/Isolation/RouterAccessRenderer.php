<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

/**
 * FLE-326: the Traefik dynamic-config file that closes the router path between instances. For every instance a deny
 * router is rendered at a high priority: a request for one of the instance's host names whose source is NOT in its allow-list
 * ends in `noop@internal` instead of reaching the instance's own (DDEV generated) router. HTTP and HTTPS get one router each;
 * when the `:443` site mirror exists, its TCP entry point forwards only an instance's names for the instance's sources and drops
 * every other connection (that entry point re-enters the HTTPS entry point from 127.0.0.1, which would otherwise hide the caller).
 *
 * Every value is an instance id, a DNS name or an IPv4 address that was validated before it got here; anything else is
 * dropped, so nothing a user typed reaches the file.
 */
final class RouterAccessRenderer
{
    public const string HEADER = "# Managed by ddev-fleet (instance isolation, FLE-326): do not edit.\n";
    public const string FILENAME = 'fleet-router-access.yaml';
    public const int PRIORITY = 100000;

    private function __construct()
    {
    }

    /**
     * @param list<RouterAccessEntry> $entries         sorted by instance id by the caller
     * @param list<string>            $httpEntryPoints the router's entry points the HTTP deny routers attach to: every one except the mirror's
     *                                                 TCP entry points. Empty: no restriction (the entry points are unknown)
     */
    public static function render(array $entries, array $httpEntryPoints = []): string
    {
        $entries = array_values(array_filter($entries, static fn (RouterAccessEntry $e): bool => [] !== self::hosts($e) && 1 === preg_match('/^[a-z0-9][a-z0-9-]*$/D', $e->instanceId)));
        if ([] === $entries) {
            return '';
        }
        $httpEntryPoints = array_values(array_unique(array_filter($httpEntryPoints, static fn (string $n): bool => 1 === preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $n))));
        sort($httpEntryPoints);
        $out = [self::HEADER];
        $out[] = "http:\n  routers:\n";
        foreach ($entries as $entry) {
            $rule = self::rule($entry);
            foreach ([['', false], ['-tls', true]] as [$suffix, $tls]) {
                $out[] = '    fleet-iso-'.$entry->instanceId.$suffix.":\n";
                if ([] !== $httpEntryPoints) {
                    $out[] = '      entryPoints: ['.implode(', ', $httpEntryPoints)."]\n";
                }
                $out[] = '      rule: "'.$rule."\"\n";
                $out[] = '      priority: '.self::PRIORITY."\n";
                $out[] = "      service: noop@internal\n";
                if ($tls) {
                    $out[] = "      tls: {}\n";
                }
            }
        }

        return implode('', $out);
    }

    /** `(Host(a) || Host(b)) && !(ClientIP(x) || ClientIP(y))`. */
    private static function rule(RouterAccessEntry $entry): string
    {
        $hosts = array_map(static fn (string $h): string => 'Host(`'.$h.'`)', self::hosts($entry));
        $sources = array_map(static fn (string $s): string => 'ClientIP(`'.$s.'`)', self::sources($entry));

        return '('.implode(' || ', $hosts).') && !('.implode(' || ', $sources).')';
    }

    /** @return list<string> */
    private static function hosts(RouterAccessEntry $entry): array
    {
        return array_values(array_unique(array_filter($entry->hosts, static fn (string $h): bool => 1 === preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/D', $h))));
    }

    /** @return list<string> the valid IPv4 addresses (as /32), sorted; the loopback is always one of them */
    private static function sources(RouterAccessEntry $entry): array
    {
        $ips = ['127.0.0.1'];
        foreach ($entry->sourceIps as $ip) {
            if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                $ips[] = $ip;
            }
        }
        $ips = array_values(array_unique($ips));
        sort($ips);

        return array_map(static fn (string $ip): string => $ip.'/32', $ips);
    }
}
