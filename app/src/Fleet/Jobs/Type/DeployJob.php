<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\Job;
use App\Fleet\Instances\DeployWorkflow;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;

/**
 * A new instance: the row exists already (the request made it), the job builds the checkout. Params: `force`, and
 * `requestedLabel` for a single deploy whose label had to change.
 */
final readonly class DeployJob implements JobType
{
    public function __construct(private InstanceRepository $instances, private DeployWorkflow $workflow)
    {
    }

    public function name(): string
    {
        return 'deploy';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $instance = null === $job->instanceId() ? null : $this->instances->find($job->instanceId());
        if (null === $instance) {
            throw new JobFailed('instance no longer exists');
        }

        $params = $job->params();
        $requested = \is_string($params['requestedLabel'] ?? null) && null === $job->batchId() ? $params['requestedLabel'] : null;

        $this->workflow->run($instance, $instance->project(), false, $log, true === ($params['force'] ?? false), null, $requested);
    }
}
