<?php

declare(strict_types=1);

namespace App\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Fleet\Instances\Teardown;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobFeed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\JobType;
use Psr\Clock\ClockInterface;

/**
 * Removes an instance: its containers and checkout, its Caddy site, then its row. The jobs and their logs stay as the
 * history. Idempotent, so a retry after a manual clean-up finishes the job; it never uses privilege.
 */
final readonly class DestroyJob implements JobType
{
    public function __construct(
        private InstanceRepository $instances,
        private Teardown $teardown,
        private JobFeed $feed,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'destroy';
    }

    public function execute(Job $job, JobLog $log): void
    {
        $id = $job->instanceId() ?? throw new JobFailed('instance no longer exists');
        $instance = $this->instances->find($id);
        if (null === $instance) {
            $log->line('the instance is already gone');

            return;
        }

        try {
            $this->teardown->removeCheckout($instance, $log);
            $this->teardown->removeSite($instance);
        } catch (\Throwable $e) {
            $instance->changeState(InstanceState::Failed, $this->clock->now());
            $this->instances->save($instance);
            $this->feed->instanceChanged($id);

            throw $e instanceof JobFailed ? $e : new JobFailed('The instance could not be removed: '.$e->getMessage());
        }

        $this->instances->remove($instance);
        $log->line('destroy complete');
        $this->feed->instanceChanged($id);
    }
}
