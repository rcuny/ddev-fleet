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
    /** Seconds each wait loop may take (the API after a restart, the PROXY trust), and for the whole rollback: nothing here may run for minutes (FLE-336). */
    public const float WAIT_BUDGET = 45.0;
    private const float POLL_SECONDS = 2.0;

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
     * @param (\Closure(string): void)|null $say progress: every line is passed here as it happens (the result lists them too)
     *
     * @return array{lines: list<string>, restarted: bool, problems: list<string>}
     */
    public function run(?\Closure $say = null): array
    {
        $say ??= static function (string $line): void {
        };
        /** @var list<string> $lines */
        $lines = [];
        $note = static function (string $line) use (&$lines, $say): void {
            $lines[] = $line;
            $say($line);
        };
        $result = $this->runSteps($note);
        $result['lines'] = $lines;

        return $result;
    }

    /**
     * @param \Closure(string): void $note
     *
     * @return array{lines: list<string>, restarted: bool, problems: list<string>}
     */
    private function runSteps(\Closure $note): array
    {
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
            $note('router: pushed '.$what);
        }
        $problems = [...$problems, ...$repair['problems']];
        if (!$enforce) {
            if ($repair['staticDiffers']) {
                $note('router: its static configuration differs from the port mirror fragment; not applied, isolation is not in enforce mode');
            }

            return ['lines' => [], 'restarted' => false, 'problems' => $problems];
        }

        if ($repair['restartNeeded']) {
            $note('router: the static configuration changed, restarting ddev-router once (every instance blinks for a few seconds; at most about 2 minutes)');
            $why = $this->router->restartForConfig() ?? $this->verifyRouter($note);
            if (null === $why) {
                $restarted = true;
                $note('router: ddev-router restarted and healthy');
            } else {
                $note('router: NOT healthy after the restart ('.$why.'), restoring the original static configuration');
                $problems[] = 'the router is not healthy after the restart ('.$why.'): '.$this->rollback($note);
                $this->settleMirrorWithoutProxy();

                return ['lines' => [], 'restarted' => true, 'problems' => $problems];
            }
        }

        $plan = $this->mirror->plan();
        $site = $plan->siteTarget;
        if (null === $site || !$plan->trust) {
            // No :443 mirror: no PROXY trust is wanted, nothing more to wait for.
            return ['lines' => [], 'restarted' => $restarted, 'problems' => $problems];
        }
        $name = MirrorPlan::siteTargetEntrypoint($site);
        $note(\sprintf('router: waiting up to %d s for the router to trust a PROXY header on %s', (int) self::WAIT_BUDGET, $name));
        if (!$this->waitForTrust($name)) {
            $problems[] = 'the router does not trust a PROXY header on '.$name.' within '.(int) self::WAIT_BUDGET.' s: the :443 mirror stays without PROXY (it works as in off mode, but instance isolation is NOT enforced on :443) until "fleet port:refresh" succeeds';

            return ['lines' => [], 'restarted' => $restarted, 'problems' => $problems];
        }

        // The router trusts it now: write the mirror files with the PROXY header and push them.
        try {
            if ($this->mirror->sync()->changed) {
                $again = $this->copies->repair();
                foreach ($again['pushed'] as $what) {
                    $note('router: pushed '.$what.' (the :443 mirror now sends PROXY)');
                }
                $problems = [...$problems, ...$again['problems']];
            }
        } catch (\RuntimeException $e) {
            $problems[] = 'the mirror files could not be written: '.$e->getMessage();
        }

        return ['lines' => [], 'restarted' => $restarted, 'problems' => $problems];
    }

    /**
     * Container healthy is checked by the restart itself; here: the Traefik API answers (bounded wait) and a real request is routed.
     *
     * @param \Closure(string): void $note
     */
    private function verifyRouter(\Closure $note): ?string
    {
        $note(\sprintf('router: waiting up to %d s for the Traefik API', (int) self::WAIT_BUDGET));
        if (!$this->poll(fn (): bool => null !== $this->live->names())) {
            return 'the Traefik API does not answer within '.(int) self::WAIT_BUDGET.' s';
        }

        return $this->probe?->check();
    }

    /**
     * Puts the original static file back and restarts once more (bounded like the first restart); returns what was done.
     *
     * @param \Closure(string): void $note
     */
    private function rollback(\Closure $note): string
    {
        if (!$this->copies->restoreStatic()) {
            return 'ROLLBACK FAILED: the original static file could not be restored; restore '.$this->copies->backupFile().' into the router by hand and restart ddev-router. The :443 mirror stays without PROXY.';
        }
        $note('router: original restored, restarting ddev-router again');
        $why = $this->router->restartForConfig() ?? $this->verifyRouter($note);
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
        return $this->poll(fn (): bool => true === $this->live->proxyTrusted($name));
    }

    /**
     * Calls `$check` until it is true or WAIT_BUDGET seconds have passed (measured, plus the sleeps a test injects): never an
     * attempt count, because one API call can itself take seconds.
     *
     * @param \Closure(): bool $check
     */
    private function poll(\Closure $check): bool
    {
        $start = hrtime(true);
        $slept = 0.0;
        while (true) {
            if ($check()) {
                return true;
            }
            $elapsed = (hrtime(true) - $start) / 1e9 + (null !== $this->sleep ? $slept : 0.0);
            if ($elapsed + self::POLL_SECONDS > self::WAIT_BUDGET) {
                return false;
            }
            if (null !== $this->sleep) {
                ($this->sleep)(self::POLL_SECONDS);
                $slept += self::POLL_SECONDS;
            } else {
                usleep((int) (self::POLL_SECONDS * 1_000_000));
            }
        }
    }
}
