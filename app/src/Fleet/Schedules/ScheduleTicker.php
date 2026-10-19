<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\Schedule;
use App\Domain\Schedule\ScheduleOutcome;
use App\Domain\Schedule\ScheduleRepository;
use App\Domain\Schedule\ScheduleRun;
use App\Domain\Schedule\ScheduleRunRepository;
use App\Domain\Schedule\ScheduleTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What one tick (every 30 seconds, from the scheduler worker) does: for each enabled schedule, deal with the latest
 * occurrence that is newer than the schedule's cursor. Older occurrences in between are never replayed, so after
 * downtime a schedule fires at most once, for its most recent occurrence, and only when that is within the catch-up window.
 */
final readonly class ScheduleTicker
{
    /** An occurrence this late (a tick or two) is on time, not a catch-up. */
    public const int ON_TIME_SECONDS = 90;
    /** After downtime the latest missed occurrence still fires when it is at most this old (0.x: 60 minutes). */
    public const int CATCH_UP_SECONDS = 3600;

    public function __construct(
        private ScheduleRepository $schedules,
        private ScheduleRunRepository $runs,
        private ScheduleLauncher $launcher,
        private ManagerRegistry $registry,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<ScheduleRun> the runs this tick created (fired, skipped, failed or missed), in schedule order
     */
    public function tick(): array
    {
        $created = [];
        $now = $this->clock->now();
        foreach ($this->schedules->enabled() as $schedule) {
            try {
                $run = $this->process($schedule, $now);
            } catch (\Throwable $e) {
                // One broken schedule must not stop the others.
                $this->logger->error('Schedule {name} could not be evaluated.', ['name' => $schedule->name(), 'exception' => $e]);
                if (!$this->em->isOpen()) {
                    $this->registry->resetManager();
                }
                continue;
            }
            if (null !== $run) {
                $created[] = $run;
            }
        }

        return $created;
    }

    private function process(Schedule $schedule, \DateTimeImmutable $now): ?ScheduleRun
    {
        $latest = CronSchedule::of($schedule->cron(), $schedule->timezone())->latest($now);
        if (null === $latest || $latest <= $schedule->cursor()) {
            return null;
        }

        $lag = $now->getTimestamp() - $latest->getTimestamp();
        $trigger = $lag <= self::ON_TIME_SECONDS ? ScheduleTrigger::Schedule : ScheduleTrigger::CatchUp;

        // The claim comes first and the cursor second: a tick that dies in between leaves a claimed row, and the next
        // tick finds it, moves the cursor and fires nothing twice.
        $run = $this->runs->claim($schedule, $latest, $trigger, $now);
        $schedule->advanceTo($latest);
        $this->schedules->save($schedule);
        if (null === $run) {
            return null;
        }

        if ($lag > self::CATCH_UP_SECONDS) {
            $run->record(ScheduleOutcome::Missed, null, 'The occurrence was more than 60 minutes old when the ticker saw it.');
            $this->runs->save($run);

            return $run;
        }

        return $this->launcher->launch($run, $schedule, JobTrigger::Schedule, 'schedule:'.$schedule->name());
    }
}
