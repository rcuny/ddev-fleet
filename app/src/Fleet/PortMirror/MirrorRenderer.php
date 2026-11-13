<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Domain\Port\NamedPort;
use App\Fleet\System\ConfigValue;

/**
 * The two Traefik files of the mirror, as text. Every number comes from an int of a validated plan, every name is
 * `fleet-mirror-<int>`, so nothing a user typed reaches the files.
 */
final class MirrorRenderer
{
    public const string HEADER = "# Managed by ddev-fleet: do not edit.\n";

    private function __construct()
    {
    }

    /**
     * Traefik static-config fragment: the :443 site entrypoint first when active, then one entrypoint per port.
     */
    public static function staticConfig(MirrorPlan $plan): string
    {
        $out = [self::HEADER, "entryPoints:\n"];
        if (null !== $plan->siteTarget) {
            // FLE-326: the router's https entry point accepts a PROXY protocol v2 header (static config, always) and the passthrough sends one (dynamic config, only when the plan says the router's live configuration trusts it)
            // from the loopback only, so the HTTP routers see the instance that connected, not 127.0.0.1.
            $out[] = '  '.MirrorPlan::siteTargetEntrypoint(ConfigValue::port($plan->siteTarget)).":\n";
            $out[] = "    proxyProtocol:\n";
            $out[] = "      trustedIPs:\n";
            $out[] = "        - \"127.0.0.1/32\"\n";
            $out[] = '  '.MirrorPlan::SITE_ENTRYPOINT.":\n";
            $out[] = '    address: ":'.MirrorPlan::SITE_PUBLIC_PORT."\"\n";
        }
        foreach ($plan->ports as $port) {
            $out[] = '  '.self::name($port).":\n";
            $out[] = '    address: ":'.ConfigValue::port($port->publicPort())."\"\n";
        }

        return implode('', $out);
    }

    /**
     * Traefik dynamic-config fragment: a TLS-terminating TCP router and a service per port, forwarding to the router's
     * own http entrypoint; the :443 site entry first, a TLS passthrough to the router's https entrypoint.
     */
    public static function dynamicConfig(MirrorPlan $plan): string
    {
        $out = [self::HEADER, "tcp:\n", "  routers:\n"];
        if (null !== $plan->siteTarget) {
            $name = MirrorPlan::SITE_ENTRYPOINT;
            $out[] = "    $name:\n";
            $out[] = "      entryPoints: [$name]\n";
            $out[] = "      rule: \"HostSNI(`*`)\"\n";
            $out[] = "      service: $name\n";
            $out[] = "      tls:\n";
            $out[] = "        passthrough: true\n";
        }
        foreach ($plan->ports as $port) {
            $name = self::name($port);
            $out[] = "    $name:\n";
            $out[] = "      entryPoints: [$name]\n";
            $out[] = "      rule: \"HostSNI(`*`)\"\n";
            $out[] = "      service: $name\n";
            $out[] = "      tls: {}\n";
        }
        $out[] = "  services:\n";
        if (null !== $plan->siteTarget) {
            $out[] = '    '.MirrorPlan::SITE_ENTRYPOINT.":\n";
            $out[] = "      loadBalancer:\n";
            $out[] = "        servers:\n";
            $out[] = '          - address: "127.0.0.1:'.ConfigValue::port($plan->siteTarget)."\"\n";
            if ($plan->proxy) {
                $out[] = "        proxyProtocol:\n";
                $out[] = "          version: 2\n";
            }
        }
        foreach ($plan->ports as $port) {
            $out[] = '    '.self::name($port).":\n";
            $out[] = "      loadBalancer:\n";
            $out[] = "        servers:\n";
            $out[] = '          - address: "127.0.0.1:'.ConfigValue::port($port->routerPort())."\"\n";
        }

        return implode('', $out);
    }

    private static function name(NamedPort $port): string
    {
        return MirrorPlan::entrypoint(ConfigValue::port($port->publicPort()));
    }
}
