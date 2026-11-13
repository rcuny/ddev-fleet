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
use Symfony\Component\Lock\LockFactory;

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
        private readonly LockFactory $locks,
        private readonly NftablesServiceGuard $nftables,
        private readonly BridgeNetfilterGuard $bridgeNf,
        private readonly ?RouterAccess $routerAccess = null,
    ) {
    }

    /**
     * One pass. Never throws: whatever goes wrong (Docker, the helper, a rendering error) comes back as `error`, so isolation
     * can never fail a deploy or a start. Two passes never run at once: a pass that finds another running does nothing.
     *
     * @param (\Closure(string): void)|null $log
     */
    public function pass(?\Closure $log = null): IsolationPass
    {
        $log ??= static function (string $line): void {
        };
        try {
            $lock = $this->locks->createLock('isolation.pass', 120.0);
            if (!$lock->acquire(false)) {
                return new IsolationPass($this->mode(), busy: true);
            }
        } catch (\Throwable $e) {
            return new IsolationPass($this->mode(), error: 'the pass lock failed: '.$e->getMessage());
        }
        try {
            return $this->locked($log);
        } catch (\Throwable $e) {
            $log('isolation: '.$e->getMessage());

            return new IsolationPass($this->mode(), error: $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function mode(): IsolationMode
    {
        try {
            return $this->host->fresh()?->isolationMode() ?? IsolationMode::Off;
        } catch (\Throwable) {
            return IsolationMode::Off;
        }
    }

    /**
     * @param \Closure(string): void $log
     */
    private function locked(\Closure $log): IsolationPass
    {
        // The row as stored now, under the lock: the watcher is one long process and its identity map would still hold the mode
        // from its first read, so a CLI `enable` made after that looked like `off` (and its table was removed). FLE-299 N2.
        $settings = $this->host->fresh();
        $mode = $settings?->isolationMode() ?? IsolationMode::Off;
        if (IsolationMode::Off === $mode) {
            // A table left behind (a disable that failed half way) is removed here: the next pass retries it.
            if (is_file($this->hashFile())) {
                $this->install(IsolationRuleset::disabled());
                @unlink($this->hashFile());
                $this->routerAccess?->sync($mode, []);
                $log('isolation: the mode is off and an earlier ruleset is recorded (applied hash file present): table removed');

                return new IsolationPass($mode, applied: true);
            }

            return new IsolationPass($mode);
        }
        if ($this->applier instanceof TargetSupport && !$this->applier->supports(ApplyTarget::Isolation)) {
            return new IsolationPass($mode, error: 'the installed root helper has no "isolation" target (run the installer of this release)');
        }
        if (null !== $problem = $this->nftables->problem()) {
            return new IsolationPass($mode, error: $problem);
        }
        $links = $this->reconcileLinks($log);
        if (null !== $problem = $this->routerAccess?->sync($mode, array_values(array_filter($links, static fn (ResolvedLink $l): bool => $l->isLive())))) {
            $log('isolation: '.$problem);
        }
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
        if (null !== $problem = $this->bridgeNf->problem()) {
            return new IsolationPass($mode, $links, applied: true, error: $problem);
        }

        return new IsolationPass($mode, $links, applied: true);
    }

    /**
     * Sets the mode and runs a pass; `off` removes the table at once. The link networks stay (harmless) unless $removeLinks.
     *
     * @throws \RuntimeException when the ruleset cannot be installed
     */
    public function changeMode(IsolationMode $mode, string $actor, bool $removeLinks = false): IsolationPass
    {
        $lock = $this->locks->createLock('isolation.pass', 120.0);
        $lock->acquire(true);
        try {
            return $this->changeModeLocked($mode, $actor, $removeLinks);
        } finally {
            $lock->release();
        }
    }

    private function changeModeLocked(IsolationMode $mode, string $actor, bool $removeLinks): IsolationPass
    {
        $settings = $this->host->fresh() ?? throw new \RuntimeException('The host is not set up yet.');
        if (IsolationMode::Off !== $mode && null !== $problem = $this->nftables->problem()) {
            throw new \RuntimeException($problem);
        }
        $before = $settings->isolationMode();
        if (IsolationMode::Off === $mode) {
            // Off removes the table whatever else fails: saving the mode, the audit entry and the link cleanup come after,
            // each on its own, and the first error is reported at the end.
            $error = null;
            try {
                $this->install(IsolationRuleset::disabled());
                @unlink($this->hashFile());
                $this->routerAccess?->sync($mode, []);
            } catch (\Throwable $e) {
                $error = $e;
            }
            try {
                $settings->changeIsolationMode($mode, new \DateTimeImmutable());
                $this->host->save($settings);
                $this->audit->record($actor, AuditLog::ISOLATION_MODE, $before->value.' -> '.$mode->value);
            } catch (\Throwable $e) {
                $error ??= $e;
            }
            if ($removeLinks) {
                $this->removeAllLinkNetworks();
            }
            if (null !== $error) {
                throw new \RuntimeException('Turning isolation off was not completed: '.$error->getMessage(), 0, $error);
            }

            return new IsolationPass($mode, applied: true);
        }
        $settings->changeIsolationMode($mode, new \DateTimeImmutable());
        $this->host->save($settings);
        $this->audit->record($actor, AuditLog::ISOLATION_MODE, $before->value.' -> '.$mode->value);

        try {
            $pass = $this->locked(static function (string $line): void {
            });
        } catch (\Throwable $e) {
            $pass = new IsolationPass($mode, error: $e->getMessage());
        }
        if (!$pass->ok()) {
            // Refuse: the mode goes back to what it was (a table the failed pass left behind is re-rendered or removed by the next pass).
            $settings->changeIsolationMode($before, new \DateTimeImmutable());
            $this->host->save($settings);
            $this->audit->record($actor, AuditLog::ISOLATION_MODE, $mode->value.' refused, mode stays '.$before->value);

            throw new \RuntimeException('Isolation was not turned on ('.$mode->value.'): '.$pass->error);
        }

        return $pass;
    }

    /**
     * Removes the networks of every link of an instance that is going away (Teardown) so no container stays attached.
     */
    public function forgetInstance(string $instanceId): void
    {
        try {
            foreach ($this->docker->linkNetworksOf($instanceId) as $network => $members) {
                $this->dropNetwork($network, $members);
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
                    $final[] = $link->pending('the network '.$network.' could not be created');
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
            if (!$ok) {
                $final[] = $link->pending('a container could not be attached to '.$network);
                continue;
            }
            $addresses = $this->docker->addresses($network);
            $from = $addresses[(string) $link->sourceContainer] ?? null;
            $to = $addresses[(string) $link->targetContainer] ?? null;
            $final[] = null === $from || null === $to ? $link->pending('the addresses on '.$network.' are not known yet') : $link->withAddresses($from, $to);
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
