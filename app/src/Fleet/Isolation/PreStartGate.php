<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Domain\Instance\Instance;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Instances\InstanceDbIsolationWriter;
use App\Fleet\Instances\MailpitHealthcheckFile;
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
        $this->enforceScanned($instance, $dir, $log, $this->violations($instance, $dir));
    }

    /**
     * @param list<OverrideViolation> $violations the result of one scan of $dir
     *
     * @throws JobFailed
     */
    private function enforceScanned(Instance $instance, string $dir, JobLog $log, array $violations): void
    {
        $this->refuse($instance, $log, $violations);
        // FLE-297: the database isolation override, on every start path (a stopped instance may predate it).
        if (is_dir($dir.'/.ddev')) {
            // FLE-313: Mailpit's basic auth must not fail DDEV's web healthcheck. After the scan: a Fleet-owned file, not scanned.
            try {
                MailpitHealthcheckFile::write($dir, $log->line(...));
            } catch (\RuntimeException $e) {
                $log->line('WARNING: isolation: the Mailpit healthcheck fragment was not written: '.$e->getMessage());
            }
            try {
                $this->dbIsolation?->write($dir, $instance->id(), $log->line(...));
            } catch (\RuntimeException $e) {
                throw new JobFailed('The database isolation override could not be written: '.$e->getMessage());
            }
        }
    }

    /**
     * FLE-317: the gate for an automatic repair (the router converge's repairing `ddev start`), never for a person's start. If the
     * scan would refuse, nothing is stopped or restarted: the instance keeps running as it is, the skip is logged and audited
     * ("repair skipped: guard would refuse (<file:key>)", `fleet instance:audit-overrides` lists the same files), and JobFailed
     * tells the caller to go no further. Otherwise it is the ordinary enforce(), on the same single scan (FLE-319).
     *
     * @throws RepairSkipped
     * @throws JobFailed
     */
    public function enforceRepair(Instance $instance, string $dir, JobLog $log): void
    {
        $violations = $this->violations($instance, $dir);
        $refused = $this->describe($instance, $violations);
        if ([] !== $refused) {
            $what = implode('; ', $refused);
            $log->line('repair skipped: guard would refuse ('.$what.'); the instance is left as it is');
            $this->audit->record('system', AuditLog::REPAIR_SKIPPED, $instance->id().': '.$what);

            throw new RepairSkipped('repair skipped: guard would refuse ('.$what.')');
        }
        $this->enforceScanned($instance, $dir, $log, $violations);
    }

    /**
     * @return list<string> the `file:key` of every violation that would make enforce() refuse (empty when it would pass)
     */
    public function refusals(Instance $instance, string $dir): array
    {
        return $this->describe($instance, $this->violations($instance, $dir));
    }

    /**
     * @return list<OverrideViolation>
     */
    private function violations(Instance $instance, string $dir): array
    {
        return $this->guard->scan($dir, [$this->paths->agentSocketDir($instance->id())]);
    }

    /**
     * @param list<OverrideViolation> $violations
     *
     * @return list<string>
     */
    private function describe(Instance $instance, array $violations): array
    {
        if ($instance->project()->allowPrivilegedOverrides()) {
            return [];
        }

        return array_map(static fn (OverrideViolation $v): string => $v->file.':'.$v->key, $violations);
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
        // FLE-324: root of the database is closed to the network (db/db stays for DDEV's tooling). A warning, never a failure.
        $this->dbIsolation?->lockRoot($instance->id(), $log->line(...));
        // FLE-331: uid 999 (fleet) is `mysql` in the db image; without this account ddev mysql / export-db / import-db fail with 1044.
        $this->dbIsolation?->ensureSocketUser($instance->id(), $log->line(...));
    }

    /**
     * @param list<OverrideViolation> $violations
     */
    private function refuse(Instance $instance, JobLog $log, array $violations): void
    {
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
