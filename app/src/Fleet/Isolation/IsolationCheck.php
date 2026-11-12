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
 * container of one running instance per project it opens TCP connections to every other project's instance, the router's
 * Traefik API, the bridge gateway's ssh port and the Internet, and compares each answer with what the mode expects:
 * off and report reach everything, enforce reaches only the Internet and the targets of live links.
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
        private \App\Fleet\Ddev\Ddev $ddev,
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

        $perProject = [];
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Running === $instance->state() && !isset($perProject[$instance->project()->name()])) {
                $perProject[$instance->project()->name()] = $instance;
            }
        }
        $rows = [];
        foreach ($perProject as $from) {
            $web = 'ddev-'.$from->id().'-web';
            foreach ($this->instances->all() as $other) {
                if (InstanceState::Running !== $other->state() || $other->project()->name() === $from->project()->name()) {
                    continue;
                }
                $present = array_column($this->ddev->projectContainers($other->id()), 'service');
                foreach ([['web', 80], ['web', 8025], ['db', 3306], ['typesense', 8108]] as [$service, $port]) {
                    if (!\in_array($service, $present, true)) {
                        continue;
                    }
                    $allowed = $this->linked($links, $from, $other, $service, $port);
                    $rows[] = $this->probe($web, $from->id(), 'ddev-'.$other->id().'-'.$service, $other->id().'/'.$service, $port, !$enforce || $allowed);
                }
            }
            $rows[] = $this->probe($web, $from->id(), DockerNetworks::ROUTER, 'router (traefik api)', 10999, !$enforce);
            if (null !== $gateway) {
                $rows[] = $this->probe($web, $from->id(), $gateway, 'host sshd '.$gateway, 22, !$enforce);
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
    private function probe(string $container, string $from, string $host, string $label, int $port, bool $shouldReach): array
    {
        $reached = $this->docker->canConnect($container, $host, $port);

        return [
            'from' => $from, 'to' => $label, 'port' => $port,
            'expected' => $shouldReach ? 'reachable' : 'blocked',
            'actual' => $reached ? 'reachable' : 'blocked',
            'ok' => $reached === $shouldReach,
        ];
    }
}
