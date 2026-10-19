<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\Job;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a job does. One implementation per job type name: 'deploy', 'redeploy', 'start', 'stop' or 'destroy'.
 */
#[AutoconfigureTag('fleet.job_type')]
interface JobType
{
    public function name(): string;

    /**
     * @throws JobFailed with a user-safe message when the job cannot complete
     */
    public function execute(Job $job, JobLog $log): void;
}
