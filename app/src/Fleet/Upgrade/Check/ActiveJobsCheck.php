<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Upgrade\Plan;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An upgrade restarts the workers, so a deploy or any job still queued or running would be cut off.
 *
 * Findings: `jobs.active` (Warn, overridable): N jobs are queued or running. Remedy: wait for them (Jobs page), or
 * pass `--force=jobs.active` to upgrade anyway.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 400])]
final readonly class ActiveJobsCheck implements UpgradeCheck
{
    public const string ACTIVE = 'jobs.active';

    public function __construct(private ActiveJobCounter $jobs)
    {
    }

    public function check(Plan $plan): array
    {
        $active = $this->jobs->count();
        if ($active <= 0) {
            return [];
        }

        return [CheckResult::warn(self::ACTIVE, \sprintf('%d %s queued or running; restarting the workers would cut %s off.', $active, 1 === $active ? 'job is' : 'jobs are', 1 === $active ? 'it' : 'them'), 'Wait for the jobs to finish (Jobs page), or pass --force='.self::ACTIVE.' to upgrade anyway.')];
    }
}
