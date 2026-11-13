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
    public const string SITE_ENTRYPOINT = 'fleet-mirror-443';
    public const int PRIORITY = 100000;
    /** Above the mirror's own catch-all (a rule of 12 characters), below every instance's allow router. */
    public const int DENY_REST_PRIORITY = 50000;
    public const string BLACKHOLE = '127.0.0.1:9';

    private function __construct()
    {
    }

    /**
     * @param list<RouterAccessEntry> $entries sorted by instance id by the caller
     */
    public static function render(array $entries, bool $siteMirror): string
    {
        $entries = array_values(array_filter($entries, static fn (RouterAccessEntry $e): bool => [] !== self::hosts($e) && 1 === preg_match('/^[a-z0-9][a-z0-9-]*$/D', $e->instanceId)));
        if ([] === $entries) {
            return '';
        }
        $out = [self::HEADER];
        $out[] = "http:\n  routers:\n";
        foreach ($entries as $entry) {
            $rule = self::rule($entry);
            foreach ([['', false], ['-tls', true]] as [$suffix, $tls]) {
                $out[] = '    fleet-iso-'.$entry->instanceId.$suffix.":\n";
                $out[] = '      rule: "'.$rule."\"\n";
                $out[] = '      priority: '.self::PRIORITY."\n";
                $out[] = "      service: noop@internal\n";
                if ($tls) {
                    $out[] = "      tls: {}\n";
                }
            }
        }
        if ($siteMirror) {
            // The mirror entry point re-enters the HTTPS entry point from 127.0.0.1, so whatever it lets through looks local to the
            // HTTP routers above. It therefore decides on the real client: an instance's names (SNI) are forwarded only for its
            // allowed sources, and EVERY other connection (another SNI, none) is dropped, whatever the Host header will say.
            $out[] = "tcp:\n  routers:\n";
            foreach ($entries as $entry) {
                $out[] = '    fleet-iso-'.$entry->instanceId.":\n";
                $out[] = '      entryPoints: ['.self::SITE_ENTRYPOINT."]\n";
                $out[] = '      rule: "'.self::allowRule($entry)."\"\n";
                $out[] = '      priority: '.self::PRIORITY."\n";
                $out[] = '      service: '.self::SITE_ENTRYPOINT."\n";
                $out[] = "      tls:\n        passthrough: true\n";
            }
            $out[] = "    fleet-iso-deny-rest:\n";
            $out[] = '      entryPoints: ['.self::SITE_ENTRYPOINT."]\n";
            $out[] = "      rule: \"HostSNI(`*`)\"\n";
            $out[] = '      priority: '.self::DENY_REST_PRIORITY."\n";
            $out[] = "      service: fleet-iso-deny\n";
            $out[] = "      tls:\n        passthrough: true\n";
            $out[] = "  services:\n    fleet-iso-deny:\n      loadBalancer:\n        servers:\n          - address: \"".self::BLACKHOLE."\"\n";
        }

        return implode('', $out);
    }

    /** `(HostSNI(a) || HostSNI(b)) && (ClientIP(x) || ClientIP(y))`: the connections the site mirror forwards. */
    private static function allowRule(RouterAccessEntry $entry): string
    {
        $hosts = array_map(static fn (string $h): string => 'HostSNI(`'.$h.'`)', self::hosts($entry));
        $sources = array_map(static fn (string $s): string => 'ClientIP(`'.$s.'`)', self::sources($entry));

        return '('.implode(' || ', $hosts).') && ('.implode(' || ', $sources).')';
    }

    /** `(Host(a) || Host(b)) && !(ClientIP(x) || ClientIP(y))`, SNI instead of Host for the TCP router. */
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
