<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Fleet\Ddev\Ddev;
use App\Fleet\Shell\CommandRunner;

/**
 * Makes the running ddev-router match the mirror fleet wants. This is the only code that restarts the router.
 *
 * Call it right after a successful `ddev start` of an instance, which has just merged the files into the router's
 * static configuration. It restarts `docker restart ddev-router` only when the router's live `fleet-mirror-*`
 * entrypoints differ from the wanted set, under the mirror lock. When the router does not come back healthy with the
 * mirror it repairs itself: removes both files, writes the failed marker, runs `ddev start` in the instance again (DDEV
 * re-merges without our files), restarts the router again and throws PortMirrorFailed.
 */
final class RouterConverger
{
    public const string ROUTER = 'ddev-router';
    private const string HEALTH_TEMPLATE = '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}';
    private const float RESTART_TIMEOUT = 120.0;
    private const float INSPECT_TIMEOUT = 10.0;
    private const float HEALTH_POLL_SECONDS = 2.0;

    private readonly \Closure $sleep;

    /**
     * @param float                        $healthWait seconds to wait for the router to be healthy after a restart
     * @param (\Closure(float): void)|null $sleep      a test passes a no-op
     */
    public function __construct(
        private readonly PortMirror $mirror,
        private readonly MirrorPlanner $planner,
        private readonly MirrorFiles $files,
        private readonly LiveEntrypoints $live,
        private readonly CommandRunner $runner,
        private readonly Ddev $ddev,
        private readonly MirrorLock $lock,
        private readonly TraefikDirectory $traefik,
        private readonly float $healthWait = 60.0,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * @param callable(string, string): void $onOutput receives the output of the repairing `ddev start`
     *
     * @return string a line for the deploy log (empty when nothing had to be done)
     *
     * @throws PortMirrorFailed
     */
    public function converge(string $instanceDir, callable $onOutput): string
    {
        if (!$this->traefik->exists()) {
            return '';
        }
        try {
            return $this->lock->run(fn (): string => $this->convergeLocked($instanceDir, $onOutput));
        } catch (MirrorLockBusy $e) {
            return $e->getMessage().'; skipped';
        }
    }

    /**
     * @param callable(string, string): void $onOutput
     */
    private function convergeLocked(string $instanceDir, callable $onOutput): string
    {
        $plan = $this->mirror->plan();
        $wanted = $plan->wantedNames();

        if ($this->files->markerBlocks($plan)) {
            return 'port mirror disabled after a failed apply; router not restarted (run "fleet port:refresh" to retry)';
        }
        if (!$this->files->isCurrent($plan)) {
            return 'port mirror files missing or stale; router not restarted';
        }

        // Read AFTER taking the lock: a concurrent deploy may have just fixed it.
        $live = $this->live->names();
        if (null === $live) {
            return [] === $wanted ? '' : 'router API unreachable; port mirror not verified';
        }
        if ($this->planner->ours($live) === $wanted) {
            return '';
        }

        $reason = $this->restartAndVerify($wanted);
        if (null === $reason) {
            return 'restarted '.self::ROUTER.': port mirror now '.([] === $plan->publicPorts() ? 'none' : implode(', ', $plan->publicPorts()));
        }

        $this->repair($plan, $instanceDir, $onOutput, $reason);
    }

    /**
     * @param list<string> $wanted
     *
     * @return string|null null when the router is back with the wanted entrypoints, else why not
     */
    private function restartAndVerify(array $wanted): ?string
    {
        if (!$this->restartRouter()) {
            return 'docker restart '.self::ROUTER.' failed';
        }
        if (!$this->routerHealthy()) {
            return \sprintf('%s was not healthy within %gs after the restart', self::ROUTER, $this->healthWait);
        }
        $after = null;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $after = $this->live->names();
            if (null !== $after && $this->planner->ours($after) === $wanted) {
                return null;
            }
            if ($attempt < 4) {
                ($this->sleep)(1.0);
            }
        }

        return \sprintf(
            '%s restarted but its live entrypoints still differ from the wanted mirror (wanted [%s], live [%s])',
            self::ROUTER,
            implode(', ', $wanted),
            implode(', ', $this->planner->ours($after ?? [])),
        );
    }

    /**
     * Never leave the shared router down because of our files, and never retry the same failing apply on every deploy.
     *
     * @param callable(string, string): void $onOutput
     *
     * @throws PortMirrorFailed always
     */
    private function repair(MirrorPlan $plan, string $instanceDir, callable $onOutput, string $reason): never
    {
        $notes = [];
        try {
            $this->files->remove();
        } catch (\Throwable $e) {
            $notes[] = 'could not remove the mirror files ('.$e->getMessage().')';
        }
        try {
            $this->files->writeMarker($plan->publicPorts());
        } catch (\Throwable $e) {
            $notes[] = 'could not write the failed-apply marker ('.$e->getMessage().')';
        }
        try {
            $start = $this->ddev->start($instanceDir, $onOutput);
            if (!$start->isSuccess()) {
                $notes[] = \sprintf('"ddev start" exited %d', $start->exitCode);
            }
        } catch (\Throwable $e) {
            $notes[] = '"ddev start" failed ('.$e->getMessage().')';
        }
        if (!$this->restartRouter()) {
            $notes[] = 'second "docker restart '.self::ROUTER.'" failed';
        }

        throw new PortMirrorFailed(\sprintf('The in-network port mirror could not be applied: %s. The mirror files were removed, "ddev start" was run again and %s restarted, so the router runs without the mirror.%s The mirror stays off (%s in %s) until you run "fleet port:refresh". Check "docker logs %s", or switch the mirror off in the host settings.', $reason, self::ROUTER, [] === $notes ? '' : ' Self-repair problems: '.implode('; ', $notes).'.', TraefikDirectory::MARKER_FILENAME, $this->traefik->dir(), self::ROUTER));
    }

    private function restartRouter(): bool
    {
        try {
            return $this->runner->run(['docker', 'restart', self::ROUTER], null, [], null, self::RESTART_TIMEOUT)->isSuccess();
        } catch (\Throwable) {
            return false;
        }
    }

    private function routerHealthy(): bool
    {
        $attempts = (int) floor($this->healthWait / self::HEALTH_POLL_SECONDS) + 1;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            try {
                $result = $this->runner->run(['docker', 'inspect', '-f', self::HEALTH_TEMPLATE, self::ROUTER], null, [], null, self::INSPECT_TIMEOUT);
                $states = array_map('trim', explode("\n", $result->stdout));
                if ($result->isSuccess() && ([] !== array_intersect($states, ['healthy', 'running']))) {
                    return true;
                }
            } catch (\Throwable) {
                // not healthy (yet)
            }
            if ($attempt < $attempts - 1) {
                ($this->sleep)(self::HEALTH_POLL_SECONDS);
            }
        }

        return false;
    }
}
