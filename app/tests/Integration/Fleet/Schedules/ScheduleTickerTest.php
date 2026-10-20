<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Schedules;

use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\ScheduleOutcome;
use App\Domain\Schedule\ScheduleTrigger;
use App\Tests\Support\ScheduleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScheduleTickerTest extends ScheduleTestCase
{
    public function testDueScheduleEnqueuesExactlyOneJobAndOneHistoryRow(): void
    {
        $this->create();
        $this->at('2026-10-21 03:00:10 UTC');

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        $jobs = $this->fakeJobs();
        self::assertCount(1, $jobs);
        self::assertSame(JobTrigger::Schedule, $jobs[0]->trigger());
        self::assertSame('schedule:nightly', $jobs[0]->requestedBy());
        $history = $this->history();
        self::assertCount(1, $history);
        self::assertSame(ScheduleTrigger::Schedule, $history[0]->trigger());
        self::assertSame(ScheduleOutcome::Claimed, $history[0]->outcome());
        self::assertSame($jobs[0]->id(), $history[0]->jobId());
        self::assertSame('2026-10-21 03:00', $history[0]->occurrence()->format('Y-m-d H:i'));
    }

    public function testTickingTwiceStillFiresOnce(): void
    {
        $this->create();
        $this->at('2026-10-21 03:00:10 UTC');

        $this->ticker->tick();
        $this->at('2026-10-21 03:00:40 UTC');
        self::assertSame([], $this->ticker->tick());

        self::assertCount(1, $this->fakeJobs());
        self::assertCount(1, $this->history());
    }

    public function testASecondTickRacingTheSameOccurrenceLosesTheClaim(): void
    {
        $this->create();
        $schedule = $this->scheduleService->get('nightly');
        $occurrence = new \DateTimeImmutable('2026-10-21 03:00:00 UTC');
        $now = new \DateTimeImmutable('2026-10-21 03:00:05 UTC');

        self::assertNotNull($this->runs->claim($schedule, $occurrence, ScheduleTrigger::Schedule, $now));
        self::assertNull($this->runs->claim($schedule, $occurrence, ScheduleTrigger::Schedule, $now));

        // The ticker then finds the claimed row, moves the cursor and fires nothing.
        $this->at('2026-10-21 03:00:10 UTC');
        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->fakeJobs());
        self::assertCount(1, $this->history());
    }

    public function testNothingFiresBeforeTheOccurrence(): void
    {
        $this->create();
        $this->at('2026-10-21 02:59:59 UTC');

        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->fakeJobs());
    }

    public function testASchedulePastItsCreationTimeDoesNotFireTheEarlierOccurrence(): void
    {
        $this->at('2026-10-21 03:30:00 UTC');
        $this->create();

        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->fakeJobs());
    }

    public function testPausedScheduleNeverFiresAndResumeDoesNotCatchUp(): void
    {
        $this->create();
        $this->scheduleService->pause('nightly', 'admin');

        $this->at('2026-10-21 03:00:10 UTC');
        self::assertSame([], $this->ticker->tick());

        // Resumed at 03:20, within the catch-up window of the missed 03:00: still nothing.
        $this->at('2026-10-21 03:20:00 UTC');
        $this->scheduleService->resume('nightly', 'admin');
        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->fakeJobs());

        // The next occurrence fires normally.
        $this->at('2026-10-22 03:00:10 UTC');
        self::assertCount(1, $this->ticker->tick());
        self::assertCount(1, $this->fakeJobs());
    }

    public function testRunNowWorksWhilePausedAndIsRecordedAsManual(): void
    {
        $this->create();
        $this->scheduleService->pause('nightly', 'admin');

        $run = $this->scheduleService->runNow('nightly', JobTrigger::Ui, 'admin');

        self::assertSame(ScheduleTrigger::Manual, $run->trigger());
        self::assertSame(ScheduleOutcome::Claimed, $run->outcome());
        $jobs = $this->fakeJobs();
        self::assertCount(1, $jobs);
        self::assertSame(JobTrigger::Ui, $jobs[0]->trigger());
        self::assertSame('admin', $jobs[0]->requestedBy());
        // Pressing it again is a second run, not a duplicate claim.
        $this->scheduleService->runNow('nightly', JobTrigger::Ui, 'admin');
        self::assertCount(2, $this->fakeJobs());
    }

    public function testRestartWithinTheWindowGivesExactlyOneCatchUpRun(): void
    {
        $this->create();
        // The ticker was down across 03:00 and 03:30-ish; it comes back at 03:45 (45 minutes late).
        $this->at('2026-10-21 03:45:00 UTC');

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame(ScheduleTrigger::CatchUp, $created[0]->trigger());
        self::assertSame(ScheduleOutcome::Claimed, $created[0]->outcome());
        self::assertCount(1, $this->fakeJobs());
        $this->at('2026-10-21 03:45:30 UTC');
        self::assertSame([], $this->ticker->tick());
        self::assertCount(1, $this->fakeJobs());
    }

    public function testOnlyTheMostRecentMissedOccurrenceCatchesUp(): void
    {
        $this->create('frequent', '*/10 * * * *');
        $this->at('2026-10-21 08:00:00 UTC');
        $this->ticker->tick();
        $before = \count($this->fakeJobs());

        // Down from 08:00 to 08:35: occurrences 08:10, 08:20 and 08:30 passed; only 08:30 fires, as a catch-up.
        $this->at('2026-10-21 08:35:00 UTC');
        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame('2026-10-21 08:30', $created[0]->occurrence()->format('Y-m-d H:i'));
        self::assertSame(ScheduleTrigger::CatchUp, $created[0]->trigger());
        self::assertCount($before + 1, $this->fakeJobs());
    }

    #[DataProvider('windowEdges')]
    public function testCatchUpWindowEdge(string $now, ScheduleOutcome $outcome, int $jobs): void
    {
        $this->create();
        $this->at($now);

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame($outcome, $created[0]->outcome());
        self::assertCount($jobs, $this->fakeJobs());
        self::assertSame($outcome->value, $this->scheduleService->result($created[0]));
    }

    /**
     * @return iterable<string, array{string, ScheduleOutcome, int}>
     */
    public static function windowEdges(): iterable
    {
        yield '59 minutes late fires' => ['2026-10-21 03:59:00 UTC', ScheduleOutcome::Claimed, 1];
        yield '60 minutes late fires' => ['2026-10-21 04:00:00 UTC', ScheduleOutcome::Claimed, 1];
        yield '61 minutes late is missed' => ['2026-10-21 04:01:00 UTC', ScheduleOutcome::Missed, 0];
    }

    #[DataProvider('downtimes')]
    public function testDowntimeFiresOnceAsACatchUpOrIsMissed(string $downFor, ScheduleOutcome $outcome, int $jobs): void
    {
        $this->create();
        // The ticker ran until 02:59:30, then stopped; the 03:00 occurrence is the one in question.
        $this->at('2026-10-21 02:59:30 UTC');
        $this->ticker->tick();

        $this->at($downFor);
        $created = $this->ticker->tick();
        $this->at('+30 seconds');
        $again = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame([], $again);
        self::assertSame($outcome, $created[0]->outcome());
        self::assertSame(ScheduleTrigger::CatchUp, $created[0]->trigger());
        self::assertCount($jobs, $this->fakeJobs());
        self::assertCount(1, $this->history());
    }

    /**
     * @return iterable<string, array{string, ScheduleOutcome, int}>
     */
    public static function downtimes(): iterable
    {
        yield '10 minutes down fires' => ['2026-10-21 03:10:00 UTC', ScheduleOutcome::Claimed, 1];
        yield '59 minutes down fires' => ['2026-10-21 03:59:00 UTC', ScheduleOutcome::Claimed, 1];
        yield '61 minutes down is missed' => ['2026-10-21 04:01:00 UTC', ScheduleOutcome::Missed, 0];
    }

    public function testALateTickWithoutDowntimeIsAScheduleRun(): void
    {
        $this->create();
        // Another tick ended 30 seconds ago (it skipped this schedule), so the ticker was running: not a catch-up.
        $this->heartbeat->beat(new \DateTimeImmutable('2026-10-21 03:01:30 UTC'));
        $this->at('2026-10-21 03:02:00 UTC');

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame(ScheduleTrigger::Schedule, $created[0]->trigger());
        self::assertSame(ScheduleOutcome::Claimed, $created[0]->outcome());
        self::assertSame(JobTrigger::Schedule, $this->fakeJobs()[0]->trigger());
    }

    public function testTheFirstTickEverCountsAsDowntime(): void
    {
        $this->create();
        $this->at('2026-10-21 03:02:00 UTC');

        self::assertSame(ScheduleTrigger::CatchUp, $this->ticker->tick()[0]->trigger());
    }

    public function testTheTickerBeingDownForMoreThanNinetySecondsMakesALateRunACatchUp(): void
    {
        $this->create();
        $this->heartbeat->beat(new \DateTimeImmutable('2026-10-21 03:00:00 UTC'));
        $this->at('2026-10-21 03:02:00 UTC');

        self::assertSame(ScheduleTrigger::CatchUp, $this->ticker->tick()[0]->trigger());
    }

    public function testResumeAndEditNeverCatchUpAfterDowntime(): void
    {
        $this->create();
        $this->scheduleService->pause('nightly', 'admin');
        $this->at('2026-10-21 03:30:00 UTC');
        $this->scheduleService->resume('nightly', 'admin');
        $this->scheduleService->update('nightly', new \App\Fleet\Schedules\ScheduleInput('nightly', '0 3 * * *', 'Europe/Paris', 'fake', null), 'admin');
        $this->at('2026-10-21 03:45:00 UTC');

        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->fakeJobs());
    }

    public function testAClaimedRunWithoutAJobIsReapedAsFailedOnceItIsOldEnough(): void
    {
        $this->create();
        $schedule = $this->scheduleService->get('nightly');
        // The ticker died right after the claim: the cursor moved, the job was never queued.
        $this->at('2026-10-21 03:00:05 UTC');
        $run = $this->runs->claim($schedule, new \DateTimeImmutable('2026-10-21 03:00:00 UTC'), ScheduleTrigger::Schedule, $this->clock->now());
        self::assertNotNull($run);
        $schedule->advanceTo(new \DateTimeImmutable('2026-10-21 03:00:00 UTC'));
        $this->schedules->save($schedule);

        // Younger than five minutes: left alone.
        $this->at('2026-10-21 03:04:00 UTC');
        $this->ticker->tick();
        self::assertSame(ScheduleOutcome::Claimed, $this->history()[0]->outcome());

        $this->at('2026-10-21 03:05:30 UTC');
        $this->ticker->tick();
        $reaped = $this->history()[0];
        self::assertSame(ScheduleOutcome::Failed, $reaped->outcome());
        self::assertNull($reaped->jobId());
        self::assertSame('The ticker stopped before the job was queued.', $reaped->detail());
        self::assertSame('failed', $this->scheduleService->result($reaped));

        // Never re-fired, and a second pass is idempotent.
        $this->at('2026-10-21 03:06:00 UTC');
        self::assertSame([], $this->ticker->tick());
        self::assertSame([], $this->fakeJobs());
        self::assertCount(1, $this->history());
        self::assertSame('The ticker stopped before the job was queued.', $this->history()[0]->detail());
    }

    public function testTheReaperLeavesRunsWithAJobAndFinishedRunsAlone(): void
    {
        $this->create();
        $this->at('2026-10-21 03:00:10 UTC');
        $fired = $this->ticker->tick()[0];
        $this->create('other');
        $this->action->refuse = 'busy';
        $this->scheduleService->runNow('other', JobTrigger::Ui, 'admin');

        $this->at('2026-10-21 03:30:00 UTC');
        $this->ticker->tick();

        self::assertSame(ScheduleOutcome::Claimed, $this->history()[0]->outcome());
        self::assertSame($fired->jobId(), $this->history()[0]->jobId());
        self::assertSame(ScheduleOutcome::Skipped, $this->history('other')[0]->outcome());
        self::assertSame('busy', $this->history('other')[0]->detail());
    }

    public function testAMissedOccurrenceIsRecordedOnceAndNeverRetried(): void
    {
        $this->create();
        $this->at('2026-10-21 05:00:00 UTC');

        $this->ticker->tick();
        $this->at('2026-10-21 05:00:30 UTC');
        $this->ticker->tick();

        $history = $this->history();
        self::assertCount(1, $history);
        self::assertSame(ScheduleOutcome::Missed, $history[0]->outcome());
        self::assertSame([], $this->fakeJobs());
    }

    public function testABusyInstanceIsRecordedAsSkipped(): void
    {
        $this->create();
        $this->action->refuse = 'Instance "acme--main" has a queued or running job.';
        $this->at('2026-10-21 03:00:10 UTC');

        $created = $this->ticker->tick();

        self::assertSame(ScheduleOutcome::Skipped, $created[0]->outcome());
        self::assertSame('skipped', $this->scheduleService->result($created[0]));
        self::assertStringContainsString('queued or running', (string) $created[0]->detail());
        self::assertNull($created[0]->jobId());
    }

    public function testAMissingTargetIsSkippedWithAReasonUntilItExists(): void
    {
        $this->create('nightly', '0 3 * * *', 'UTC', 'gone');
        $this->at('2026-10-21 03:00:10 UTC');

        $created = $this->ticker->tick();

        self::assertSame(ScheduleOutcome::Skipped, $created[0]->outcome());
        self::assertStringContainsString('gone does not exist yet', (string) $created[0]->detail());
        self::assertNull($created[0]->jobId());
        self::assertSame([], $this->fakeJobs());
        self::assertSame(0, $this->action->started);

        // The same schedule pointed at an existing instance runs normally.
        $this->scheduleService->update('nightly', new \App\Fleet\Schedules\ScheduleInput('nightly', '0 3 * * *', 'UTC', 'fake', 'acme--main'), 'admin');
        $this->at('2026-10-22 03:00:10 UTC');
        $created = $this->ticker->tick();

        self::assertSame(ScheduleOutcome::Claimed, $created[0]->outcome());
        self::assertSame(1, $this->action->started);
    }

    public function testResultFollowsTheJobStatus(): void
    {
        $this->create();
        $this->at('2026-10-21 03:00:10 UTC');
        $run = $this->ticker->tick()[0];
        self::assertSame('claimed', $this->scheduleService->result($run));

        $job = $this->fakeJobs()[0];
        $job->markRunning($this->clock->now());
        $this->jobs->save($job);
        self::assertSame('started', $this->scheduleService->result($run));

        $this->service->finish($job, \App\Domain\Job\JobStatus::Succeeded, 0, null);
        self::assertSame('succeeded', $this->scheduleService->result($run));
    }

    public function testATimezoneIsHonoured(): void
    {
        $this->create('brussels', '0 3 * * *', 'Europe/Brussels');
        // 03:00 in Brussels on 2026-10-21 is 01:00 UTC.
        $this->at('2026-10-21 00:59:00 UTC');
        self::assertSame([], $this->ticker->tick());
        $this->at('2026-10-21 01:00:05 UTC');
        self::assertCount(1, $this->ticker->tick());
    }

    public function testASchedulesFailureDoesNotStopTheOthers(): void
    {
        $this->create('first');
        $this->create('second');
        $this->at('2026-10-21 03:00:10 UTC');
        // A schedule whose stored cron became unreadable is logged and skipped.
        $this->em->getConnection()->executeStatement("UPDATE schedule SET cron = 'broken' WHERE name = 'first'");
        $this->em->clear();

        $created = $this->ticker->tick();

        self::assertCount(1, $created);
        self::assertSame('second', $created[0]->schedule()->name());
    }
}
