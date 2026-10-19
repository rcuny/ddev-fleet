<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Schedules;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Schedule\ScheduleRun;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\ValidationFailed;
use App\Fleet\Schedules\RedeployAction;
use App\Fleet\Schedules\ScheduleActions;
use App\Fleet\Schedules\ScheduleInput;
use App\Tests\Support\ScheduleTestCase;

final class ScheduleServiceTest extends ScheduleTestCase
{
    public function testCreateStoresAndAudits(): void
    {
        $this->create('nightly', ' 0 3 * * * ', 'Europe/Brussels');

        $schedule = $this->scheduleService->get('nightly');
        self::assertSame('0 3 * * *', $schedule->cron());
        self::assertSame('Europe/Brussels', $schedule->timezone());
        self::assertTrue($schedule->isEnabled());
        self::assertSame(['schedule.create'], $this->auditActions());
    }

    public function testInvalidValuesAreFieldErrors(): void
    {
        try {
            $this->scheduleService->create(new ScheduleInput('Bad Name', '61 * * * *', 'Mars/Base', 'nope', null), 'admin');
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['name', 'cron', 'timezone', 'action'], array_keys($e->errors));
        }
        self::assertSame([], $this->scheduleService->list());
    }

    public function testTheActionValidatesItsTarget(): void
    {
        try {
            $this->scheduleService->create(new ScheduleInput('nightly', '0 3 * * *', 'UTC', 'fake', 'gone'), 'admin');
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['instance'], array_keys($e->errors));
        }
    }

    public function testNamesAreUnique(): void
    {
        $this->create();

        $this->expectException(ValidationFailed::class);
        $this->create();
    }

    public function testUpdateKeepsTheNameAndMovesTheCursorWhenTheTimingChanges(): void
    {
        $this->create();
        $this->at('2026-10-21 09:00:00 UTC');

        $this->scheduleService->update('nightly', new ScheduleInput('ignored', '0 8 * * *', 'UTC', 'fake', null), 'admin');

        $schedule = $this->scheduleService->get('nightly');
        self::assertSame('0 8 * * *', $schedule->cron());
        // 08:00 already passed when the cron was set: it must not fire for it.
        self::assertSame([], $this->ticker->tick());
        self::assertSame(['schedule.create', 'schedule.update'], $this->auditActions());
    }

    public function testUpdateRefusesAnInvalidCron(): void
    {
        $this->create();

        $this->expectException(ValidationFailed::class);
        $this->scheduleService->update('nightly', new ScheduleInput('nightly', 'nonsense', 'UTC', 'fake', null), 'admin');
    }

    public function testPauseAndResumeAreAuditedOnce(): void
    {
        $this->create();

        $this->scheduleService->pause('nightly', 'admin');
        $this->scheduleService->pause('nightly', 'admin');
        self::assertFalse($this->scheduleService->get('nightly')->isEnabled());
        self::assertNull($this->scheduleService->nextFire($this->scheduleService->get('nightly')));
        $this->scheduleService->resume('nightly', 'admin');

        self::assertTrue($this->scheduleService->get('nightly')->isEnabled());
        self::assertSame('2026-10-21 03:00', $this->scheduleService->nextFire($this->scheduleService->get('nightly'))?->format('Y-m-d H:i'));
        self::assertSame(['schedule.create', 'schedule.pause', 'schedule.resume'], $this->auditActions());
    }

    public function testRunNowIsAudited(): void
    {
        $this->create();
        $this->scheduleService->runNow('nightly', \App\Domain\Job\JobTrigger::Cli, 'cli');

        self::assertSame(['schedule.create', 'schedule.run'], $this->auditActions());
    }

    public function testDeleteRemovesTheHistoryToo(): void
    {
        $this->create();
        $this->scheduleService->runNow('nightly', \App\Domain\Job\JobTrigger::Ui, 'admin');
        self::assertCount(1, $this->em->getRepository(ScheduleRun::class)->findAll());

        $this->scheduleService->delete('nightly', 'admin');

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(ScheduleRun::class)->findAll());
        self::assertSame(['schedule.create', 'schedule.run', 'schedule.delete'], $this->auditActions());
        $this->expectException(NotFound::class);
        $this->scheduleService->get('nightly');
    }

    public function testTheRealContainerRegistersTheRedeployAction(): void
    {
        $actions = self::getContainer()->get(ScheduleActions::class);
        self::assertInstanceOf(ScheduleActions::class, $actions);
        self::assertSame(['redeploy'], $actions->names());

        $redeploy = $actions->get('redeploy');
        self::assertInstanceOf(RedeployAction::class, $redeploy);
        self::assertSame('Enter the id of the instance to redeploy.', $redeploy->targetError(null));
        self::assertSame('This instance does not exist.', $redeploy->targetError('nope--main'));
    }

    /**
     * @return list<string> oldest first
     */
    private function auditActions(): array
    {
        $entries = array_reverse(new AuditEntryRepository($this->em)->latest(50));

        return array_map(static fn ($entry): string => $entry->action(), $entries);
    }
}
