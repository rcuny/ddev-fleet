<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Type;

use App\Domain\Job\Job;
use App\Fleet\Host\Drift\DriftCheck;
use App\Fleet\Host\Drift\DriftCheckFailed;
use App\Fleet\Host\Drift\DriftReport;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;

/**
 * The drift check as a job (FLE-261): a read-only playbook run (--check --diff) through the root helper `apply drift`.
 * The log gets a summary, never the raw playbook output; the report is stored as the host's last one and shown on
 * /admin/host. Drift found is a result, not a failure; a task that could not be checked fails the job.
 */
final readonly class DriftJob implements JobType
{
    public function __construct(private DriftCheck $check)
    {
    }

    public function name(): string
    {
        return 'drift';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $log->line('Checking the host against the installed release (read-only, this takes a few minutes).');
        try {
            $report = $this->check->run();
        } catch (DriftCheckFailed $e) {
            throw new JobFailed($e->getMessage());
        }
        foreach (self::summary($report) as $line) {
            $log->line($line);
        }
        if ($report->hasFailures()) {
            throw new JobFailed(\sprintf('The check could not evaluate every task in %d role(s). The report lists them.', \count($report->withStatus('failed'))));
        }
    }

    /**
     * @return list<string>
     */
    public static function summary(DriftReport $report): array
    {
        $lines = null === $report->warning() ? [] : ['WARNING: '.$report->warning()];
        foreach ($report->roles as $role) {
            $lines[] = \sprintf('%s: %s (%d changed, %d drift message(s), %d failed)', $role['role'], $role['status'], \count($role['changed']), \count($role['drift']), \count($role['failed']));
        }
        $lines[] = $report->hasDrift() ? 'Drift found.' : 'No drift.';
        $lines[] = DriftReport::LIMITS;

        return $lines;
    }
}
