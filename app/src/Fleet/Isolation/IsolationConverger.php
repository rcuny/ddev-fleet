<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\IsolationMode;
use App\Domain\Port\NamedPortRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Ddev\DdevUnavailable;
use App\Fleet\FleetPaths;
use App\Fleet\Ssh\AtomicFile;
use App\Fleet\System\Applier;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\SystemRenderer;
use App\Fleet\System\TargetSupport;

/**
 * FLE-299: one host-wide pass of instance network isolation. In mode `off` it does nothing. Otherwise: (1) the link networks
 * are made true to the declared links (created, the two containers attached, orphans removed), (2) the ruleset is rendered
 * from the bridge, the router's address, the host ports and the live links, and (3) installed through `apply isolation`
 * only when it differs from what was installed last (the recorded flows are carried over, so they survive).
 *
 * Called after every start and restart (PreStartGate::verifyStarted), after a router restart, by the watcher every 120 s
 * and by the commands. A failure never throws: it is returned and logged, and the next pass retries.
 */
final class IsolationConverger
{
    public function __construct(
        private readonly HostSettingsRepository $host,
        private readonly NamedPortRepository $ports,
        private readonly LinkResolver $resolver,
        private readonly DockerNetworks $docker,
        private readonly IsolationReport $report,
        private readonly SystemRenderer $system,
        private readonly Applier $applier,
        private readonly FleetPaths $paths,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $log
     */
    public function pass(?\Closure $log = null): IsolationPass
    {
        $log ??= static function (string $line): void {
        };
        $settings = $this->host->get();
        $mode = $settings?->isolationMode() ?? IsolationMode::Off;
        if (IsolationMode::Off === $mode) {
            return new IsolationPass($mode);
        }
        if ($this->applier instanceof TargetSupport && !$this->applier->supports(ApplyTarget::Isolation)) {
            return new IsolationPass($mode, error: 'the installed root helper has no "isolation" target (run the installer of this release)');
        }
        try {
            $links = $this->reconcileLinks($log);
            $bridge = $this->docker->bridgeName(DockerNetworks::SHARED);
            if (null === $bridge) {
                return new IsolationPass($mode, $links, applied: false);
            }
            $live = $this->report->read();
            if (null === $live) {
                return new IsolationPass($mode, $links, error: 'the live table could not be read through the root helper');
            }
            $input = $this->input($mode, $bridge, $settings, $links, $live);
            $hash = IsolationRuleset::hash($input);
            if ($this->appliedHash() === $hash && $live['installed']) {
                return new IsolationPass($mode, $links, applied: false);
            }
            $this->install(IsolationRuleset::render($input));
            $this->recordHash($hash);
            $log('isolation: ruleset installed ('.$mode->value.' mode, '.\count($input->links).' link(s))');

            return new IsolationPass($mode, $links, applied: true);
        } catch (DdevUnavailable|\RuntimeException $e) {
            $log('isolation: '.$e->getMessage());

            return new IsolationPass($mode, error: $e->getMessage());
        }
    }

    /**
     * Sets the mode and runs a pass; `off` removes the table at once. The link networks stay (harmless) unless $removeLinks.
     *
     * @throws \RuntimeException when the ruleset cannot be installed
     */
    public function changeMode(IsolationMode $mode, string $actor, bool $removeLinks = false): IsolationPass
    {
        $settings = $this->host->get() ?? throw new \RuntimeException('The host is not set up yet.');
        $before = $settings->isolationMode();
        $settings->changeIsolationMode($mode, new \DateTimeImmutable());
        $this->host->save($settings);
        $this->audit->record($actor, AuditLog::ISOLATION_MODE, $before->value.' -> '.$mode->value);
        if (IsolationMode::Off === $mode) {
            $this->install(IsolationRuleset::disabled());
            @unlink($this->hashFile());
            if ($removeLinks) {
                $this->removeAllLinkNetworks();
            }

            return new IsolationPass($mode, applied: true);
        }

        return $this->pass();
    }

    /**
     * Removes the networks of every link of an instance that is going away (Teardown) so no container stays attached.
     */
    public function forgetInstance(string $instanceId): void
    {
        try {
            foreach ($this->docker->linkNetworks() as $network => $members) {
                if (str_contains($network, LinkIds::NETWORK_PREFIX.$instanceId.'--') || str_contains($network, '--'.$instanceId.'--')) {
                    $this->dropNetwork($network, $members);
                }
            }
        } catch (DdevUnavailable) {
            // The next pass removes the orphan.
        }
    }

    /**
     * @param (\Closure(string): void) $log
     *
     * @return list<ResolvedLink> the links with their final state
     *
     * @throws DdevUnavailable
     */
    private function reconcileLinks(\Closure $log): array
    {
        $links = $this->resolver->resolve();
        $existing = $this->docker->linkNetworks();
        $desired = [];
        $final = [];
        foreach ($links as $link) {
            if (!$link->isLive()) {
                $final[] = $link;
                continue;
            }
            $network = $link->network();
            if (!isset($existing[$network])) {
                if (!$this->docker->createLinkNetwork($link)) {
                    $final[] = $this->pending($link, 'the network '.$network.' could not be created');
                    continue;
                }
                $log('isolation: created '.$network);
                $existing[$network] = [];
            }
            $members = $existing[$network];
            $ok = true;
            if (!\in_array((string) $link->sourceContainer, $members, true)) {
                $ok = $this->docker->connect($network, (string) $link->sourceContainer);
            }
            if ($ok && !\in_array((string) $link->targetContainer, $members, true)) {
                $ok = $this->docker->connect($network, (string) $link->targetContainer, $link->alias());
            }
            $desired[$network] = true;
            $final[] = $ok ? $link : $this->pending($link, 'a container could not be attached to '.$network);
        }
        foreach ($existing as $network => $members) {
            if (!isset($desired[$network])) {
                $this->dropNetwork($network, $members);
                $log('isolation: removed '.$network);
            }
        }

        return $final;
    }

    /**
     * @param list<string> $members
     */
    private function dropNetwork(string $network, array $members): void
    {
        foreach ($members as $container) {
            $this->docker->disconnect($network, $container);
        }
        $this->docker->remove($network);
    }

    private function removeAllLinkNetworks(): void
    {
        try {
            foreach ($this->docker->linkNetworks() as $network => $members) {
                $this->dropNetwork($network, $members);
            }
        } catch (DdevUnavailable) {
        }
    }

    private function pending(ResolvedLink $link, string $why): ResolvedLink
    {
        return new ResolvedLink($link->sourceInstance, $link->sourceProject, $link->targetInstance, $link->targetProject, $link->service, $link->port, $why, $link->sourceContainer, $link->targetContainer);
    }

    /**
     * @param list<ResolvedLink>                                                                                                                    $links
     * @param array{installed: bool, flows: list<array{0: string, 1: string, 2: int}>, host: list<array{0: string, 1: int}>, routers: list<string>} $live
     *
     * @throws DdevUnavailable
     */
    private function input(IsolationMode $mode, string $bridge, ?HostSettings $settings, array $links, array $live): IsolationInput
    {
        return new IsolationInput(
            $mode,
            $bridge,
            $this->docker->routerAddresses(),
            array_map(static fn ($p): int => $p->publicPort(), $this->ports->all()),
            $settings?->isolationHostPorts() ?? [],
            array_values(array_filter($links, static fn (ResolvedLink $l): bool => $l->isLive())),
            $live['flows'],
            $live['host'],
        );
    }

    private function install(string $ruleset): void
    {
        try {
            $this->system->stageFile(ApplyTarget::Isolation, 'fleet_isolation.nft', $ruleset);
            $this->applier->apply(ApplyTarget::Isolation);
        } finally {
            $this->system->discardStaging(ApplyTarget::Isolation);
        }
    }

    private function hashFile(): string
    {
        return $this->paths->home().'/isolation-applied.sha256';
    }

    private function appliedHash(): ?string
    {
        $text = @file_get_contents($this->hashFile());

        return \is_string($text) ? trim($text) : null;
    }

    private function recordHash(string $hash): void
    {
        AtomicFile::write($this->hashFile(), $hash."\n", 0o640);
    }
}
