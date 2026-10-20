<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\Schedule;
use App\Fleet\Instances\InstanceService;
use App\Fleet\Instances\OperationOptions;
use App\Fleet\Instances\Selector;
use App\Fleet\Projects\NotFound;

/**
 * The 0.x nightly redeploy: the schedule's instance is redeployed through InstanceService::request.
 */
final readonly class RedeployAction implements ScheduleAction
{
    public const string NAME = 'redeploy';

    public function __construct(private InstanceService $instances, private InstanceRepository $repository)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function targetError(?string $instanceId): ?string
    {
        if (null === $instanceId || '' === $instanceId) {
            return 'Enter the id of the instance to redeploy.';
        }

        return null;
    }

    public function targetMissing(?string $instanceId): bool
    {
        return null === $instanceId || '' === $instanceId || null === $this->repository->find($instanceId);
    }

    public function start(Schedule $schedule, JobTrigger $trigger, string $requestedBy): ?int
    {
        $id = $schedule->instanceId() ?? throw NotFound::instance('');
        $jobs = $this->instances->request('redeploy', new Selector(ids: [$id]), new OperationOptions(), $trigger, $requestedBy);

        return $jobs[0]->id();
    }
}
