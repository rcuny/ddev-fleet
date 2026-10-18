<?php

declare(strict_types=1);

namespace App\Fleet\Shell;

/**
 * Picks the runner named by FLEET_RUNNER. Receives both shared runner services
 * so the recording instance tests inspect is the one the app uses.
 */
final readonly class CommandRunnerFactory
{
    public function __construct(
        private ProcessCommandRunner $process,
        private RecordingCommandRunner $recording,
    ) {
    }

    public function create(string $kind): CommandRunner
    {
        return match ($kind) {
            'process' => $this->process,
            'recording' => $this->recording,
            default => throw new \InvalidArgumentException(\sprintf('Unknown FLEET_RUNNER "%s"; allowed values: process, recording.', $kind)),
        };
    }
}
