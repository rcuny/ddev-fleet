<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Port\NamedPortRepository;

/**
 * The in-network mirror of the catalogue's public ports (FLE-29) and of :443 (FLE-32).
 *
 * The named ports exist only on the host's Caddy. Inside an instance's containers an instance host name resolves to
 * ddev-router on the Docker network, which does not listen there, so an in-container browser cannot open the URL the
 * site hands out. The mirror makes the router listen on the same public ports inside the Docker network only: two
 * files in the fleet user's `~/.ddev/traefik/` (an entrypoint per port; a TLS-terminating TCP router forwarding to the
 * router's own http entrypoint), which DDEV merges on its next `ddev start`. :443 gets one more entrypoint with a TLS
 * passthrough to `router_https_port` (8443 on a fleet host, where Caddy owns 443).
 *
 * sync() only writes files and never touches the router. The single place that restarts it is RouterConverger, called
 * right after a successful `ddev start` of a deploy. The switch is HostSettings::portMirror().
 */
final readonly class PortMirror
{
    public function __construct(
        private HostSettingsRepository $settings,
        private NamedPortRepository $ports,
        private MirrorPlanner $planner,
        private MirrorFiles $files,
        private MirrorLock $lock,
        private LiveEntrypoints $live,
        private TraefikDirectory $traefik,
    ) {
    }

    /**
     * What the catalogue and the switch ask for right now.
     */
    public function plan(): MirrorPlan
    {
        return $this->planner->plan($this->enabled(), $this->ports->all());
    }

    public function enabled(): bool
    {
        return $this->settings->get()?->portMirror() ?? true;
    }

    /**
     * Writes (or, when nothing is to be mirrored, removes) the two Traefik files, atomically and only when the content
     * changed. While the failed-apply marker matches the wanted ports nothing is written. Never restarts the router.
     *
     * @throws \RuntimeException when a file cannot be written or removed
     */
    public function sync(): MirrorSync
    {
        try {
            return $this->lock->run(fn (): MirrorSync => $this->syncLocked());
        } catch (MirrorLockBusy $e) {
            return new MirrorSync(skipped: [$e->getMessage().'; files not updated']);
        }
    }

    /**
     * The explicit retry: forgets a failed apply so the next sync and converge act again.
     *
     * @return bool true when there was a marker
     *
     * @throws \RuntimeException
     */
    public function clearFailedMarker(): bool
    {
        return $this->traefik->exists() && $this->files->clearMarker();
    }

    /**
     * True when the running router has what fleet wants: its live `fleet-mirror-*` entrypoints equal the wanted ones. An
     * unreachable router API counts as live only when nothing is wanted. It never restarts anything.
     */
    public function isLive(?MirrorPlan $plan = null): bool
    {
        $plan ??= $this->plan();
        $live = $this->live->names();
        if (null === $live) {
            return $plan->isEmpty();
        }

        return $this->planner->ours($live) === $plan->wantedNames();
    }

    private function syncLocked(): MirrorSync
    {
        $plan = $this->plan();
        if (!$this->traefik->exists()) {
            return new MirrorSync(skipped: $plan->skipped);
        }
        if ($this->files->markerBlocks($plan)) {
            return new MirrorSync(skipped: [...$plan->skipped, \sprintf(
                'mirror disabled: a previous apply failed (%s present); fix the cause, then run "fleet port:refresh" to retry',
                $this->traefik->markerFile(),
            )]);
        }
        if ($plan->isEmpty()) {
            return new MirrorSync(changed: $this->files->remove(), skipped: $plan->skipped);
        }

        return new MirrorSync(changed: $this->files->write($plan), mirrored: $plan->publicPorts(), skipped: $plan->skipped);
    }
}
