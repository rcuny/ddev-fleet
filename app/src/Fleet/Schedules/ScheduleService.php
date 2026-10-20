<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\Schedule;
use App\Domain\Schedule\ScheduleOutcome;
use App\Domain\Schedule\ScheduleRepository;
use App\Domain\Schedule\ScheduleRun;
use App\Domain\Schedule\ScheduleRunRepository;
use App\Domain\Schedule\ScheduleTrigger;
use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\ValidationFailed;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * The one path to manage schedules, shared by the pages and the `fleet:schedule:*` commands. Each change is audited.
 * Failures are ValidationFailed (field => message), NotFound; a run that could not start is a history row, not an exception.
 */
final readonly class ScheduleService
{
    public function __construct(
        private ScheduleRepository $schedules,
        private ScheduleRunRepository $runs,
        private ScheduleActions $actions,
        private ScheduleLauncher $launcher,
        private JobRepository $jobs,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<Schedule> ordered by name
     */
    public function list(): array
    {
        return $this->schedules->all();
    }

    /**
     * @throws NotFound
     */
    public function get(string $name): Schedule
    {
        return $this->schedules->findByName($name) ?? throw NotFound::schedule($name);
    }

    /**
     * @return list<string> the action names a schedule can have
     */
    public function actionNames(): array
    {
        return $this->actions->names();
    }

    /**
     * @throws ValidationFailed
     */
    public function create(ScheduleInput $input, string $by, bool $requireTarget = true): Schedule
    {
        $input = $input->normalised();
        $errors = $this->errors($input, true, $requireTarget);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $now = $this->clock->now();
        $schedule = new Schedule($input->name, $input->cron, $input->timezone, $input->action, $input->instance, $now);

        try {
            $this->schedules->save($schedule);
        } catch (UniqueConstraintViolationException) {
            // Two creates raced past the check. The entity manager is closed now: touch nothing else.
            throw new ValidationFailed(['name' => 'A schedule with this name was created just now.']);
        }
        $this->audit->record($by, AuditLog::SCHEDULE_CREATE, $schedule->name());

        return $schedule;
    }

    /**
     * Changes everything but the name. A changed expression or timezone moves the cursor to the latest occurrence, so
     * the new timing never fires for a moment that already passed.
     *
     * @throws NotFound
     * @throws ValidationFailed
     */
    public function update(string $name, ScheduleInput $input, string $by, bool $requireTarget = true): Schedule
    {
        $schedule = $this->get($name);
        $input = new ScheduleInput($schedule->name(), $input->cron, $input->timezone, $input->action, $input->instance);
        $input = $input->normalised();
        $errors = $this->errors($input, false, $requireTarget);
        if ([] !== $errors) {
            throw new ValidationFailed($errors);
        }

        $timingChanged = $input->cron !== $schedule->cron() || $input->timezone !== $schedule->timezone();
        $now = $this->clock->now();
        $schedule->change($input->cron, $input->timezone, $input->action, $input->instance, $now);
        if ($timingChanged) {
            $this->skipTo($schedule, $now);
        }
        $this->schedules->save($schedule);
        $this->audit->record($by, AuditLog::SCHEDULE_UPDATE, $schedule->name());

        return $schedule;
    }

    /**
     * @throws NotFound
     */
    public function delete(string $name, string $by): void
    {
        $schedule = $this->get($name);
        $this->schedules->remove($schedule);
        $this->audit->record($by, AuditLog::SCHEDULE_DELETE, $name);
    }

    /**
     * @throws NotFound
     */
    public function pause(string $name, string $by): Schedule
    {
        $schedule = $this->get($name);
        if ($schedule->isEnabled()) {
            $schedule->setEnabled(false, $this->clock->now());
            $this->schedules->save($schedule);
            $this->audit->record($by, AuditLog::SCHEDULE_PAUSE, $name);
        }

        return $schedule;
    }

    /**
     * Resuming never catches up: the cursor jumps to the latest occurrence, so only future occurrences fire.
     *
     * @throws NotFound
     */
    public function resume(string $name, string $by): Schedule
    {
        $schedule = $this->get($name);
        if (!$schedule->isEnabled()) {
            $now = $this->clock->now();
            $schedule->setEnabled(true, $now);
            $this->skipTo($schedule, $now);
            $this->schedules->save($schedule);
            $this->audit->record($by, AuditLog::SCHEDULE_RESUME, $name);
        }

        return $schedule;
    }

    /**
     * Fires the action now, paused or not. The run is recorded as manual; the job carries the caller's trigger.
     *
     * @throws NotFound
     */
    public function runNow(string $name, JobTrigger $trigger, string $by): ScheduleRun
    {
        $schedule = $this->get($name);
        $now = $this->clock->now();
        $run = new ScheduleRun($schedule, $now, ScheduleTrigger::Manual, $now);
        $this->runs->save($run);
        $this->audit->record($by, AuditLog::SCHEDULE_RUN, $name);

        return $this->launcher->launch($run, $schedule, $trigger, $by);
    }

    /**
     * @return list<ScheduleRun> newest first
     *
     * @throws NotFound
     */
    public function history(string $name, int $limit = 50): array
    {
        return $this->runs->latest($this->get($name), $limit);
    }

    public function lastRun(Schedule $schedule): ?ScheduleRun
    {
        return $this->runs->lastOf($schedule);
    }

    /**
     * The next occurrence after now, or null when the schedule is paused or has none.
     */
    public function nextFire(Schedule $schedule): ?\DateTimeImmutable
    {
        if (!$schedule->isEnabled()) {
            return null;
        }
        try {
            return CronSchedule::of($schedule->cron(), $schedule->timezone())->next($this->clock->now());
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * How a run ended, in the 0.x words: claimed, started, succeeded, failed, skipped or missed. A queued job is
     * still "claimed", a running one "started".
     */
    public function result(ScheduleRun $run): string
    {
        if (ScheduleOutcome::Claimed !== $run->outcome() || null === $run->jobId()) {
            return $run->outcome()->value;
        }

        return match ($this->jobs->find($run->jobId())?->status()) {
            JobStatus::Running => 'started',
            JobStatus::Succeeded => 'succeeded',
            JobStatus::Failed, JobStatus::Interrupted => 'failed',
            default => 'claimed',
        };
    }

    /**
     * The field => message map create() would refuse the input with, without touching the database. A project import
     * checks a file with it before it writes anything; $checkTarget false accepts an instance that does not exist yet.
     *
     * @return array<string, string>
     */
    public function validate(ScheduleInput $input, bool $checkName = true, bool $checkTarget = true): array
    {
        return $this->errors($input->normalised(), $checkName, $checkTarget);
    }

    /**
     * @return array<string, string> field => message
     */
    private function errors(ScheduleInput $input, bool $checkName, bool $checkTarget): array
    {
        $errors = [];
        if ($checkName) {
            if (null !== $error = ResourceName::error($input->name)) {
                $errors['name'] = $error;
            } elseif (null !== $this->schedules->findByName($input->name)) {
                $errors['name'] = 'A schedule with this name already exists.';
            }
        }
        $errors += CronSchedule::errors($input->cron, $input->timezone);

        $action = $this->actions->get($input->action);
        if (null === $action) {
            $errors['action'] = \sprintf('Choose one of: %s.', implode(', ', $this->actions->names()));
        } elseif ($checkTarget && null !== $error = $action->targetError($input->instance)) {
            $errors['instance'] = $error;
        }

        return $errors;
    }

    private function skipTo(Schedule $schedule, \DateTimeImmutable $now): void
    {
        $latest = CronSchedule::of($schedule->cron(), $schedule->timezone())->latest($now);
        if (null !== $latest) {
            $schedule->advanceTo($latest);
        }
    }
}
