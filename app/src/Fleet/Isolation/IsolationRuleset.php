<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\IsolationMode;

/**
 * FLE-299: renders the nftables table `inet fleet_isolation` (pure text, no side effects). One `nft -f` transaction deletes
 * and recreates the table, so a re-apply never leaves stale elements. Both chains have `policy accept` and sit before Docker's
 * filter chains (priority -10): an empty or missing table changes nothing, and the mode decides whether the last rule drops.
 *
 *  - forward: the router reaches every routed service; instances reach the router's HTTP and mirror ports; ICMP stays;
 *    every other new flow inside ddev_default is recorded in `observed` and (enforce) dropped. Per link bridge: only
 *    `source -> target:port` (new connections), nothing else.
 *  - input: containers reach the host only on 80/443, the public catalogue ports, the admin's extra ports, DNS and ICMP.
 */
final class IsolationRuleset
{
    public const string TABLE = 'fleet_isolation';
    public const array ROUTER_PORTS = [80, 443, 8080, 8443, 8025, 8026];
    public const array HOST_PORTS = [80, 443];

    public static function render(IsolationInput $in): string
    {
        if (IsolationMode::Off === $in->mode) {
            return self::disabled();
        }
        self::interface($in->bridge);
        $verdict = IsolationMode::Enforce === $in->mode ? 'drop' : 'accept';

        $routers = self::addresses($in->routerIps);
        $hostPorts = self::ports([...self::HOST_PORTS, ...$in->publicPorts, ...$in->extraPorts]);
        $routerPorts = self::ports([...self::ROUTER_PORTS, ...$in->publicPorts]);

        $observed = array_map(static fn (array $f): string => self::address($f[0]).' . '.self::address($f[1]).' . '.self::port($f[2]), $in->observedFlows);
        $observedHost = array_map(static fn (array $f): string => self::address($f[0]).' . '.self::port($f[1]), $in->observedHost);

        $out = "table inet fleet_isolation {}\ndelete table inet fleet_isolation\n";
        $out .= "table inet fleet_isolation {\n";
        $out .= "  set routers {\n    type ipv4_addr;\n".self::elements($routers)."  }\n";
        $out .= "  set host_ports {\n    type inet_service;\n".self::elements($hostPorts)."  }\n";
        $out .= "  set router_ports {\n    type inet_service;\n".self::elements($routerPorts)."  }\n";
        $out .= "  set observed {\n    type ipv4_addr . ipv4_addr . inet_service;\n    flags dynamic,timeout;\n    timeout 4h;\n    size 65535;\n".self::elements($observed)."  }\n";
        $out .= "  set observed_host {\n    type ipv4_addr . inet_service;\n    flags dynamic,timeout;\n    timeout 4h;\n    size 16384;\n".self::elements($observedHost)."  }\n";

        $out .= "  chain forward {\n    type filter hook forward priority -10; policy accept;\n";
        $out .= "    ct state established,related accept\n";
        $b = '"'.$in->bridge.'"';
        $out .= "    iifname $b oifname $b ip saddr @routers accept\n";
        $out .= "    iifname $b oifname $b ip daddr @routers tcp dport @router_ports accept\n";
        $out .= "    iifname $b oifname $b ip protocol icmp accept\n";
        $out .= "    iifname $b oifname $b ct state new update @observed { ip saddr . ip daddr . tcp dport timeout 4h }\n";
        $out .= "    iifname $b oifname $b ct state new counter $verdict\n";
        $links = $in->links;
        usort($links, static fn (ResolvedLink $a, ResolvedLink $b): int => strcmp($a->bridge(), $b->bridge()));
        $seen = [];
        foreach ($links as $link) {
            $name = $link->bridge();
            self::interface($name);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out .= "    # link {$link->describe()}\n";
            $out .= "    iifname \"$name\" oifname \"$name\" tcp dport {$link->port} accept\n";
            $out .= "    iifname \"$name\" oifname \"$name\" ct state new drop\n";
        }
        $out .= "  }\n";

        $out .= "  chain input {\n    type filter hook input priority -10; policy accept;\n";
        foreach (['"br-*"', '"docker0"', '"flk-*"'] as $i) {
            $out .= "    iifname $i ct state established,related accept\n";
            $out .= "    iifname $i tcp dport @host_ports accept\n";
            $out .= "    iifname $i udp dport 53 accept\n";
            $out .= "    iifname $i ip protocol icmp accept\n";
            $out .= "    iifname $i ct state new update @observed_host { ip saddr . tcp dport timeout 4h }\n";
            $out .= "    iifname $i ct state new counter $verdict\n";
        }
        $out .= "  }\n}\n";

        return $out;
    }

    /** What `disable` stages: the table is created (so the delete cannot fail) and deleted. */
    public static function disabled(): string
    {
        return "table inet fleet_isolation {}\ndelete table inet fleet_isolation\n";
    }

    /** The hash of the ruleset without the recorded flows: when it is unchanged nothing is applied. */
    public static function hash(IsolationInput $in): string
    {
        return hash('sha256', self::render($in->withoutObserved()));
    }

    public static function interface(string $name): string
    {
        if (1 !== preg_match('/^[a-z0-9-]{1,15}$/D', $name)) {
            throw new \InvalidArgumentException('An interface name is 1 to 15 characters of a-z, 0-9 and dashes.');
        }

        return $name;
    }

    /**
     * @param list<int> $ports
     *
     * @return list<string>
     */
    private static function ports(array $ports): array
    {
        $ports = array_values(array_unique($ports));
        sort($ports);

        return array_map(self::port(...), $ports);
    }

    private static function port(int $port): string
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('A port is 1 to 65535.');
        }

        return (string) $port;
    }

    /**
     * @param list<string> $ips
     *
     * @return list<string>
     */
    private static function addresses(array $ips): array
    {
        $ips = array_values(array_unique(array_map(self::address(...), $ips)));
        sort($ips);

        return $ips;
    }

    private static function address(string $ip): string
    {
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('Not an IPv4 address.');
        }

        return $ip;
    }

    /**
     * @param list<string> $items
     */
    private static function elements(array $items): string
    {
        return [] === $items ? '' : '    elements = { '.implode(', ', $items)." }\n";
    }
}
