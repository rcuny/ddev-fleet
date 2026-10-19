<?php

declare(strict_types=1);

namespace App\Command\Schedule;

use App\Domain\Schedule\Schedule;
use App\Domain\Schedule\ScheduleRun;
use App\Fleet\Schedules\ScheduleService;

/**
 * The JSON objects of a schedule and of a history row (0.x key names where there was one).
 */
final readonly class ScheduleShapes
{
    private const string TIME = 'Y-m-d\TH:i:s\Z';

    public function __construct(private ScheduleService $schedules)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function schedule(Schedule $schedule): array
    {
        $last = $this->schedules->lastRun($schedule);

        return [
            'name' => $schedule->name(),
            'cron' => $schedule->cron(),
            'timezone' => $schedule->timezone(),
            'action' => $schedule->action(),
            'instance' => $schedule->instanceId(),
            'enabled' => $schedule->isEnabled(),
            'next_fire' => self::time($this->schedules->nextFire($schedule)),
            'last_result' => null === $last ? null : $this->schedules->result($last),
            'last_occurrence' => self::time($schedule->lastOccurrence()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(ScheduleRun $run): array
    {
        return [
            'id' => $run->id(),
            'occurrence' => self::time($run->occurrence()),
            'trigger' => $run->trigger()->value,
            'result' => $this->schedules->result($run),
            'job' => $run->jobId(),
            'detail' => $run->detail(),
            'created_at' => self::time($run->createdAt()),
        ];
    }

    private static function time(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME);
    }
}
