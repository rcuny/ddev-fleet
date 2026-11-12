<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\IsolationMode;
use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Fleet\Ddev\DdevUnavailable;

/**
 * FLE-299: the read-only picture behind `fleet host:isolation status`: the mode, whether the table is installed, the links
 * with their state and the flows the table recorded, each mapped to instances with the `project:link:create` line that would
 * allow it (the dry-run listing of the links a host would need before `enforce`).
 */
final readonly class IsolationStatus
{
    public function __construct(
        private HostSettingsRepository $host,
        private InstanceRepository $instances,
        private LinkResolver $resolver,
        private DockerNetworks $docker,
        private IsolationReport $report,
    ) {
    }

    /**
     * @return array<string, mixed> the documented JSON schema; `ok` is false when enforce is on and the live state does not match
     */
    public function build(): array
    {
        $settings = $this->host->get();
        $mode = $settings?->isolationMode() ?? IsolationMode::Off;
        $doc = [
            'mode' => $mode->value,
            'docker' => true,
            'table' => ['installed' => false, 'readable' => false],
            'routers' => [],
            'hostPorts' => IsolationRuleset::HOST_PORTS,
            'extraHostPorts' => $settings?->isolationHostPorts() ?? [],
            'links' => [],
            'flows' => [],
            'hostFlows' => [],
            'problems' => [],
            'ok' => true,
        ];
        try {
            $live = $this->report->read();
            $links = $this->resolver->resolve();
            $containers = $this->docker->sharedContainers();
        } catch (DdevUnavailable $e) {
            $doc['docker'] = false;
            $doc['problems'][] = 'docker did not answer: '.$e->getMessage();
            $doc['ok'] = IsolationMode::Enforce !== $mode;

            return $doc;
        }
        $doc['links'] = array_map(static fn (ResolvedLink $l): array => [
            'source' => $l->sourceInstance, 'target' => $l->targetInstance, 'service' => $l->service, 'port' => $l->port,
            'state' => $l->isLive() ? 'live' : 'pending', 'reason' => $l->pending,
        ], $links);
        if (null === $live) {
            $doc['problems'][] = 'the live table could not be read (older root helper, or no sudo)';
        } else {
            $doc['table'] = ['installed' => $live['installed'], 'readable' => true];
            $doc['routers'] = $live['routers'];
            $byIp = array_flip($containers);
            $doc['flows'] = $this->flows($live['flows'], $byIp, $links);
            $doc['hostFlows'] = array_map(fn (array $f): array => ['source' => $this->owner($byIp[$f[0]] ?? null) ?? $f[0], 'port' => $f[1]], $live['host']);
            $running = array_filter($this->instances->all(), static fn (Instance $i): bool => 'running' === $i->state()->value);
            if (IsolationMode::Off !== $mode && !$live['installed']) {
                $doc['problems'][] = 'the mode is '.$mode->value.' but the table is not installed';
            }
            if (IsolationMode::Off !== $mode && [] === $live['routers'] && [] !== $running) {
                $doc['problems'][] = 'the router set is empty while instances run';
            }
            if (IsolationMode::Off === $mode && $live['installed']) {
                $doc['problems'][] = 'the table exists although the mode is off (applied by hand?)';
            }
        }
        $doc['ok'] = IsolationMode::Enforce !== $mode || [] === $doc['problems'];

        return $doc;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int}> $flows
     * @param array<string, string>                     $byIp
     * @param list<ResolvedLink>                        $links
     *
     * @return list<array<string, mixed>>
     */
    private function flows(array $flows, array $byIp, array $links): array
    {
        $out = [];
        foreach ($flows as [$src, $dst, $port]) {
            $source = $this->instanceOf($byIp[$src] ?? null);
            $target = $this->instanceOf($byIp[$dst] ?? null);
            if (null === $source || null === $target || $source[0]->id() === $target[0]->id()) {
                $out[] = ['source' => $byIp[$src] ?? $src, 'target' => $byIp[$dst] ?? $dst, 'port' => $port, 'covered' => false, 'suggestion' => null];
                continue;
            }
            $covered = false;
            foreach ($links as $l) {
                if ($l->sourceInstance === $source[0]->id() && $l->targetInstance === $target[0]->id() && $l->port === $port) {
                    $covered = true;
                }
            }
            $out[] = [
                'source' => $source[0]->id(), 'target' => $target[0]->id().'/'.$target[1], 'port' => $port, 'covered' => $covered,
                'suggestion' => $covered ? null : \sprintf('fleet project:link:create %s %s --service=%s --port=%d', $source[0]->project()->name(), $target[0]->project()->name(), $target[1], $port),
            ];
        }

        return $out;
    }

    private function owner(?string $container): ?string
    {
        $found = $this->instanceOf($container);

        return null === $found ? $container : $found[0]->id().'/'.$found[1];
    }

    /**
     * @return array{0: Instance, 1: string}|null the instance and the compose service a container name belongs to
     */
    private function instanceOf(?string $container): ?array
    {
        if (null === $container) {
            return null;
        }
        foreach ($this->instances->all() as $instance) {
            $prefix = 'ddev-'.$instance->id().'-';
            if (str_starts_with($container, $prefix)) {
                return [$instance, substr($container, \strlen($prefix))];
            }
        }

        return null;
    }
}
