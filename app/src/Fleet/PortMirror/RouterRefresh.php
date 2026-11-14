<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

/**
 * FLE-326: makes the running router hold what Fleet wrote for it (RouterCopies) and, when the static part changed, restarts it
 * once. Called by `fleet port:refresh` (the installer runs it after every upgrade) and by `host:isolation:enable`; the watcher's
 * isolation pass only pushes files, never restarts (a restart is a blink for every instance).
 *
 * The order is the safety: the :443 mirror may send a PROXY header only to a router whose LIVE configuration already trusts it
 * (`PortMirror::plan()` reads that from the Traefik API). So: write the mirror files for what is live now (no PROXY while the
 * router does not trust it), push the copies, restart once if the static part changed, wait until the API shows the trust, and only
 * then write the files again (now with PROXY) and push them. If any step fails the mirror stays without PROXY: it works, and
 * isolation on :443 is not enforced (said loudly in the problems).
 */
final readonly class RouterRefresh
{
    private const int TRUST_WAIT_ATTEMPTS = 15;

    public function __construct(
        private RouterCopies $copies,
        private RouterConverger $router,
        private PortMirror $mirror,
        private LiveEntrypoints $live,
        private ?\Closure $sleep = null,
        private ?RouterProbe $probe = null,
    ) {
    }

    /**
     * @return array{lines: list<string>, restarted: bool, problems: list<string>}
     */
    public function run(): array
    {
        $lines = [];
        $problems = [];
        $restarted = false;
        try {
            $this->mirror->sync();
        } catch (\RuntimeException $e) {
            return ['lines' => [], 'restarted' => false, 'problems' => ['the mirror files could not be written: '.$e->getMessage()]];
        }
        $enforce = $this->mirror->isolationEnforced();
        // Outside enforce mode the router's static configuration is never pushed and the router never restarted: a difference is only reported.
        $repair = $this->copies->repair($enforce);
        foreach ($repair['pushed'] as $what) {
            $lines[] = 'router: pushed '.$what;
        }
        $problems = [...$problems, ...$repair['problems']];
        if (!$enforce) {
            if ($repair['staticDiffers']) {
                $lines[] = 'router: its static configuration differs from the port mirror fragment; not applied, isolation is not in enforce mode';
            }

            return ['lines' => $lines, 'restarted' => false, 'problems' => $problems];
        }

        if ($repair['restartNeeded']) {
            $lines[] = 'router: the static configuration changed, restarting ddev-router once (every instance blinks for a few seconds)';
            $why = $this->router->restartForConfig() ?? $this->verifyRouter();
            if (null === $why) {
                $restarted = true;
                $lines[] = 'router: ddev-router restarted and healthy';
            } else {
                $problems[] = 'the router is not healthy after the restart ('.$why.'): '.$this->rollback();
                $this->settleMirrorWithoutProxy();

                return ['lines' => $lines, 'restarted' => true, 'problems' => $problems];
            }
        }

        $plan = $this->mirror->plan();
        $site = $plan->siteTarget;
        if (null === $site || !$plan->trust) {
            // No :443 mirror: no PROXY trust is wanted, nothing more to wait for.
            return ['lines' => $lines, 'restarted' => $restarted, 'problems' => $problems];
        }
        $name = MirrorPlan::siteTargetEntrypoint($site);
        if (!$this->waitForTrust($name)) {
            $problems[] = 'the router does not trust a PROXY header on '.$name.' yet: the :443 mirror stays without PROXY (it works, but instance isolation is NOT enforced on :443) until "fleet port:refresh" succeeds';

            return ['lines' => $lines, 'restarted' => $restarted, 'problems' => $problems];
        }

        // The router trusts it now: write the mirror files with the PROXY header and push them.
        try {
            if ($this->mirror->sync()->changed) {
                $again = $this->copies->repair();
                foreach ($again['pushed'] as $what) {
                    $lines[] = 'router: pushed '.$what.' (the :443 mirror now sends PROXY)';
                }
                $problems = [...$problems, ...$again['problems']];
            }
        } catch (\RuntimeException $e) {
            $problems[] = 'the mirror files could not be written: '.$e->getMessage();
        }

        return ['lines' => $lines, 'restarted' => $restarted, 'problems' => $problems];
    }

    /** Container healthy is checked by the restart itself; here: the Traefik API answers and a real request is routed. */
    private function verifyRouter(): ?string
    {
        $answered = false;
        for ($attempt = 0; $attempt < self::TRUST_WAIT_ATTEMPTS && !$answered; ++$attempt) {
            $answered = null !== $this->live->names();
            if (!$answered && $attempt < self::TRUST_WAIT_ATTEMPTS - 1) {
                null !== $this->sleep ? ($this->sleep)(1.0) : usleep(1_000_000);
            }
        }
        if (!$answered) {
            return 'the Traefik API does not answer';
        }

        return $this->probe?->check();
    }

    /** Puts the original static file back and restarts once more; returns what was done, for the error message. */
    private function rollback(): string
    {
        if (!$this->copies->restoreStatic()) {
            return 'ROLLBACK FAILED: the original static file could not be restored; restore '.$this->copies->backupFile().' into the router by hand and restart ddev-router. The :443 mirror stays without PROXY.';
        }
        $why = $this->router->restartForConfig() ?? $this->verifyRouter();
        if (null !== $why) {
            return 'the original static configuration was restored but the router is still not healthy ('.$why.'): check ddev-router by hand. The :443 mirror stays without PROXY.';
        }

        return 'the original static configuration was restored and the router restarted; instance isolation is NOT enforced on :443 and the mirror stays without PROXY.';
    }

    /** After a rollback the router does not trust the header (API says so), so this rewrites the mirror without it and pushes that. */
    private function settleMirrorWithoutProxy(): void
    {
        try {
            $this->mirror->sync();
            $this->copies->repair(false);
        } catch (\RuntimeException) {
        }
    }

    private function waitForTrust(string $name): bool
    {
        for ($attempt = 0; $attempt < self::TRUST_WAIT_ATTEMPTS; ++$attempt) {
            if (true === $this->live->proxyTrusted($name)) {
                return true;
            }
            if ($attempt < self::TRUST_WAIT_ATTEMPTS - 1) {
                null !== $this->sleep ? ($this->sleep)(1.0) : usleep(1_000_000);
            }
        }

        return false;
    }
}
