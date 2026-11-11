<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Instance\Instance;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;

/**
 * The one pre-start step (FLE-298): every path that runs `ddev start` or `ddev restart` on an instance calls enforce() first
 * (deploy, start, restart, the router converge's repair start). It scans the checkout's `.ddev/` files with the
 * ComposeOverrideGuard and refuses, naming the file and key. A project an admin switched `allow_privileged_overrides` on for is
 * let through, and the violations are logged and audited.
 */
final readonly class InstanceIsolation
{
    public function __construct(
        private ComposeOverrideGuard $guard,
        private AuditLog $audit,
        private FleetPaths $paths,
    ) {
    }

    /**
     * @throws JobFailed
     */
    public function enforce(Instance $instance, string $dir, JobLog $log): void
    {
        $violations = $this->guard->scan($dir, [$this->paths->agentSocketDir($instance->id())]);
        if ([] === $violations) {
            return;
        }
        $lines = array_map(static fn (OverrideViolation $v): string => $v->message(), $violations);
        if ($instance->project()->allowPrivilegedOverrides()) {
            $log->line('warning: privileged compose overrides allowed for this project (allow_privileged_overrides): '.implode('; ', $lines));
            $this->audit->record('system', AuditLog::DEPLOY_OVERRIDES_ALLOWED, $instance->id().': '.implode('; ', $lines));

            return;
        }
        foreach ($lines as $line) {
            $log->line('refused: '.$line);
        }

        throw new JobFailed(\sprintf('Refused: the project ships DDEV files that would give its containers access to the host: %s. Remove them (or keep them out of the repository), or ask an admin to switch on privileged overrides for the project. Nothing was started.', implode('; ', $lines)));
    }
}
