<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\Schedule;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a schedule does when it fires: one implementation per action name. It builds and queues a job through the
 * generic job API and returns its id; the schedule never runs a process itself.
 */
#[AutoconfigureTag('fleet.schedule_action')]
interface ScheduleAction
{
    public function name(): string;

    /**
     * Why the target is not acceptable, or null: 'redeploy' needs an existing instance.
     */
    public function targetError(?string $instanceId): ?string;

    /**
     * @return int|null the id of the queued job, null when the action queued none
     *
     * @throws Refused  when the action cannot start now (the instance is busy)
     * @throws NotFound when the target no longer exists
     */
    public function start(Schedule $schedule, JobTrigger $trigger, string $requestedBy): ?int;
}
