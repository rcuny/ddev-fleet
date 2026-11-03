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
 * Rebuilds an instance from its recorded parameters (project, branch, label) with the template as it is now, or with
 * the one the request named (and the branch, when the request names another one). A retried deploy is a redeploy: the checkout may be half there.
 */
final readonly class RedeployJob implements JobType
{
    public function __construct(private InstanceRepository $instances, private DeployWorkflow $workflow)
    {
    }

    public function name(): string
    {
        return 'redeploy';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $instance = null === $job->instanceId() ? null : $this->instances->find($job->instanceId());
        if (null === $instance) {
            throw new JobFailed('instance no longer exists');
        }

        $params = $job->params();
        $template = \is_string($params['template'] ?? null) && '' !== $params['template'] ? $params['template'] : null;

        // Absent on jobs queued before the key existed: the instance keeps its branch then.
        $branch = \is_string($params['branch'] ?? null) && '' !== $params['branch'] ? $params['branch'] : null;
        if (null !== $branch && $branch !== $instance->branch()) {
            $instance->changeBranch($branch);
            $this->instances->save($instance);
        }

        $this->workflow->run($instance, $instance->project(), true, $log, true === ($params['force'] ?? false), $template);
    }
}
