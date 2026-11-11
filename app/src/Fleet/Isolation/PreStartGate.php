<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Instance\Instance;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceDbIsolationWriter;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;

/**
 * The one pre-start step (FLE-298): every path that runs `ddev start` or `ddev restart` on an instance calls enforce() first
 * (deploy, start, restart, the router converge's repair start). It scans the checkout's `.ddev/` files with the
 * ComposeOverrideGuard and refuses, naming the file and key. A project an admin switched `allow_privileged_overrides` on for is
 * let through, and the violations are logged and audited.
 */
final readonly class PreStartGate
{
    public function __construct(
        private ComposeOverrideGuard $guard,
        private AuditLog $audit,
        private FleetPaths $paths,
        private ?InstanceDbIsolationWriter $dbIsolation = null,
    ) {
    }

    /**
     * @throws JobFailed
     */
    public function enforce(Instance $instance, string $dir, JobLog $log): void
    {
        $this->scan($instance, $dir, $log);
        // FLE-297: the database isolation override, on every start path (a stopped instance may predate it).
        if (is_dir($dir.'/.ddev')) {
            try {
                $this->dbIsolation?->write($dir, $instance->id(), $log->line(...));
            } catch (\RuntimeException $e) {
                throw new JobFailed('The database isolation override could not be written: '.$e->getMessage());
            }
        }
    }

    /**
     * FLE-297: call it after every successful `ddev start` or `ddev restart`. Disconnects the db (and other non-web) containers
     * from the shared ddev_default network, FLE-307 (see InstanceDbIsolationWriter::converge()).
     *
     * @throws JobFailed
     */
    public function verifyStarted(Instance $instance, string $dir, JobLog $log): void
    {
        try {
            $this->dbIsolation?->converge($instance->id(), $log->line(...));
        } catch (\RuntimeException $e) {
            throw new JobFailed('The database isolation is not in effect: '.$e->getMessage());
        }
    }

    private function scan(Instance $instance, string $dir, JobLog $log): void
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
