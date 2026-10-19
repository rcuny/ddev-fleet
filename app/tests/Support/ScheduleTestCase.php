<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Job\Job;
use App\Domain\Schedule\ScheduleRepository;
use App\Domain\Schedule\ScheduleRun;
use App\Domain\Schedule\ScheduleRunRepository;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Schedules\ScheduleActions;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleLauncher;
use App\Fleet\Schedules\ScheduleService;
use App\Fleet\Schedules\ScheduleTicker;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;

/**
 * The schedule core wired by hand over the job test case: a mock clock (2026-10-20 09:00 UTC), the recording bus and a
 * fake action that queues a job of type "fake-nightly".
 */
abstract class ScheduleTestCase extends JobTestCase
{
    protected ScheduleRepository $schedules;
    protected ScheduleRunRepository $runs;
    protected FakeScheduleAction $action;
    protected ScheduleTicker $ticker;
    protected ScheduleService $scheduleService;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->schedules = new ScheduleRepository($this->em);
        $this->runs = new ScheduleRunRepository($this->em);
        $this->action = new FakeScheduleAction($this->service);
        $actions = new ScheduleActions([$this->action]);
        $launcher = new ScheduleLauncher($actions, $this->runs, $registry, $this->em, new NullLogger());
        $this->ticker = new ScheduleTicker($this->schedules, $this->runs, $launcher, $registry, $this->em, $this->clock, new NullLogger());
        $this->scheduleService = new ScheduleService(
            $this->schedules,
            $this->runs,
            $actions,
            $launcher,
            $this->jobs,
            new AuditLog(new AuditEntryRepository($this->em), $this->clock),
            $this->clock,
        );
    }

    protected function create(string $name = 'nightly', string $cron = '0 3 * * *', string $timezone = 'UTC', ?string $instance = null): void
    {
        $this->scheduleService->create(new ScheduleInput($name, $cron, $timezone, 'fake', $instance), 'admin');
    }

    protected function at(string $time): void
    {
        $this->clock->modify($time);
    }

    /**
     * @return list<Job>
     */
    protected function fakeJobs(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(Job::class)->findAll(),
            static fn (Job $job): bool => FakeScheduleAction::TYPE === $job->type(),
        ));
    }

    /**
     * @return list<ScheduleRun>
     */
    protected function history(string $name = 'nightly'): array
    {
        return $this->scheduleService->history($name);
    }
}
