<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\IsolationMode;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Ddev\DdevUnavailable;

/**
 * FLE-299: the live probe the orchestrator runs after each rollout step (`fleet host:isolation check`). From the web
 * container of one running instance per project it opens TCP connections to every other project's web container (80 and the
 * Mailpit port), the router's Traefik API, the bridge gateway's ssh port and the Internet, plus every live link. It asserts
 * only what the mode promises: enforce blocks the first four and allows the Internet and the links; off and report promise
 * nothing about reachability (a host firewall may block ssh, a stopped service answers nothing), so those probes are listed as
 * "any" and never fail. The Internet and live links must be reachable in every mode. In report and enforce mode br_netfilter
 * must be active, or the table sees no traffic inside a bridge.
 */
final readonly class IsolationCheck
{
    public const string INTERNET_HOST = '1.1.1.1';
    public const int INTERNET_PORT = 443;

    public function __construct(
        private HostSettingsRepository $host,
        private InstanceRepository $instances,
        private LinkResolver $resolver,
        private DockerNetworks $docker,
        private ?BridgeNetfilterGuard $bridgeNf = null,
        private ?RouterAccess $routerAccess = null,
    ) {
    }

    /**
     * @return list<array{from: string, to: string, port: int, expected: string, actual: string, ok: bool}>
     *
     * @throws DdevUnavailable
     */
    public function run(): array
    {
        $mode = $this->host->get()?->isolationMode() ?? IsolationMode::Off;
        $enforce = IsolationMode::Enforce === $mode;
        $links = array_filter($this->resolver->resolve(), static fn (ResolvedLink $l): bool => $l->isLive());
        $gateway = $this->docker->gateway();
        $rows = [];
        if (IsolationMode::Off !== $mode && null !== $problem = $this->bridgeNf?->problem()) {
            $rows[] = ['from' => 'host', 'to' => 'br_netfilter', 'port' => 0, 'expected' => 'active', 'actual' => 'inactive: '.$problem, 'ok' => false];
        }

        if (null !== $copy = $this->routerAccess?->verify($mode)) {
            // FLE-326: the router reads its own copy of the allow-list; a host file it never received protects nothing.
            $rows[] = ['from' => 'host', 'to' => 'router access file in ddev-router', 'port' => 0, ...$copy];
        }

        $perProject = [];
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Running === $instance->state() && !isset($perProject[$instance->project()->name()])) {
                $perProject[$instance->project()->name()] = $instance;
            }
        }
        foreach ($perProject as $from) {
            $web = 'ddev-'.$from->id().'-web';
            foreach ($this->instances->all() as $other) {
                if (InstanceState::Running !== $other->state() || $other->project()->name() === $from->project()->name()) {
                    continue;
                }
                // Only the web container is on the shared network: db, Typesense and the like sit on the project network and are never reachable across projects.
                foreach ([80, 8025] as $port) {
                    // By container name this probe asks Docker DNS, which may answer with the ddev_default address (dropped) or, for a
                    // web link, the link network's: a linked web:port is therefore not asserted here (the `link` row below proves it
                    // through the alias, FLE-326 live check); everything else must be blocked in enforce.
                    $linked = $this->linked($links, $from, $other, 'web', $port);
                    $rows[] = $this->probe($web, $from->id(), 'ddev-'.$other->id().'-web', $other->id().'/web', $port, $enforce && !$linked ? false : null);
                }
            }
            $rows[] = $this->probe($web, $from->id(), DockerNetworks::ROUTER, 'router (traefik api)', 10999, $enforce ? false : null);
            if (null !== $gateway) {
                $rows[] = $this->probe($web, $from->id(), $gateway, 'host sshd '.$gateway, 22, $enforce ? false : null);
            }
            $rows[] = $this->probe($web, $from->id(), self::INTERNET_HOST, 'internet', self::INTERNET_PORT, true);
        }
        foreach ($links as $l) {
            $rows[] = $this->probe('ddev-'.$l->sourceInstance.'-web', $l->sourceInstance, $l->alias(), 'link '.$l->targetInstance.'/'.$l->service, $l->port, true);
        }

        return $rows;
    }

    /**
     * @param iterable<ResolvedLink> $links
     */
    private function linked(iterable $links, Instance $from, Instance $to, string $service, int $port): bool
    {
        foreach ($links as $l) {
            if ($l->sourceInstance === $from->id() && $l->targetInstance === $to->id() && $l->service === $service && $l->port === $port) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{from: string, to: string, port: int, expected: string, actual: string, ok: bool}
     */
    private function probe(string $container, string $from, string $host, string $label, int $port, ?bool $shouldReach): array
    {
        $reached = $this->docker->canConnect($container, $host, $port);
        if (null === $shouldReach) {
            return ['from' => $from, 'to' => $label, 'port' => $port, 'expected' => 'any', 'actual' => $reached ? 'reachable' : 'blocked', 'ok' => true];
        }

        return [
            'from' => $from, 'to' => $label, 'port' => $port,
            'expected' => $shouldReach ? 'reachable' : 'blocked',
            'actual' => $reached ? 'reachable' : 'blocked',
            'ok' => $reached === $shouldReach,
        ];
    }
}
