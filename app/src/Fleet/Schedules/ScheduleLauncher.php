<?php

declare(strict_types=1);

namespace App\Fleet\Schedules;

use App\Domain\Job\JobTrigger;
use App\Domain\Schedule\Schedule;
use App\Domain\Schedule\ScheduleOutcome;
use App\Domain\Schedule\ScheduleRun;
use App\Domain\Schedule\ScheduleRunRepository;
use App\Fleet\Projects\NotFound;
use App\Fleet\Projects\Refused;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Starts the action of a claimed run and writes down how that went. Never throws for an action failure: the history
 * row says it, and the ticker goes on with the next schedule.
 */
final readonly class ScheduleLauncher
{
    public function __construct(
        private ScheduleActions $actions,
        private ScheduleRunRepository $runs,
        private ManagerRegistry $registry,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function launch(ScheduleRun $run, Schedule $schedule, JobTrigger $trigger, string $requestedBy): ScheduleRun
    {
        $runId = (int) $run->id();
        [$outcome, $jobId, $detail] = $this->start($schedule, $trigger, $requestedBy);

        // A failed flush inside the action closes the entity manager: carry on with a fresh one.
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
            $run = $this->runs->find($runId) ?? $run;
        }
        $run->record($outcome, $jobId, $detail);
        $this->runs->save($run);

        return $run;
    }

    /**
     * @return array{ScheduleOutcome, int|null, string|null} outcome, job id, detail
     */
    private function start(Schedule $schedule, JobTrigger $trigger, string $requestedBy): array
    {
        $action = $this->actions->get($schedule->action());
        if (null === $action) {
            return [ScheduleOutcome::Failed, null, \sprintf('The action "%s" is not available.', $schedule->action())];
        }

        try {
            return [ScheduleOutcome::Claimed, $action->start($schedule, $trigger, $requestedBy), null];
        } catch (Refused $e) {
            return [ScheduleOutcome::Skipped, null, $e->getMessage()];
        } catch (NotFound $e) {
            return [ScheduleOutcome::Failed, null, $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('Schedule {name} could not start its action.', ['name' => $schedule->name(), 'exception' => $e]);

            return [ScheduleOutcome::Failed, null, 'The action could not be started.'];
        }
    }
}
