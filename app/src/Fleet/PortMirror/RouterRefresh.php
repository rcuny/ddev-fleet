<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

/**
 * FLE-326: makes the running router hold what Fleet wrote for it (RouterCopies) and, when the static part changed, restarts it
 * once. Called by `fleet port:refresh` (the installer runs it after every upgrade) and by `host:isolation:enable`; the watcher's
 * isolation pass only pushes the dynamic file, never restarts (a restart is a blink for every instance).
 */
final readonly class RouterRefresh
{
    public function __construct(private RouterCopies $copies, private RouterConverger $router)
    {
    }

    /**
     * @return array{lines: list<string>, restarted: bool, problems: list<string>}
     */
    public function run(): array
    {
        $repair = $this->copies->repair();
        $lines = array_map(static fn (string $p): string => 'router: pushed '.$p, $repair['pushed']);
        $problems = $repair['problems'];
        $restarted = false;
        if ($repair['restartNeeded']) {
            $lines[] = 'router: the static configuration changed, restarting ddev-router once (every instance blinks for a few seconds)';
            $why = $this->router->restartForConfig();
            if (null === $why) {
                $restarted = true;
                $lines[] = 'router: ddev-router restarted and healthy';
            } else {
                $problems[] = 'the router restart failed: '.$why;
            }
        }

        return ['lines' => $lines, 'restarted' => $restarted, 'problems' => $problems];
    }
}
