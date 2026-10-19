<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Job\JobRepository;
use App\Fleet\FleetPaths;
use Psr\Clock\ClockInterface;

/**
 * Deletes the history that outlived its retention: finished jobs older than N days and their log files. A log file is
 * only ever removed from inside the logs directory, by a path FleetPaths builds from the job's numeric id and a validated
 * instance id.
 */
final readonly class JobPruner
{
    public function __construct(
        private JobRepository $jobs,
        private FleetPaths $paths,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the number of jobs deleted
     */
    public function prune(int $days): int
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('The retention must be at least one day.');
        }
        $before = $this->clock->now()->modify(\sprintf('-%d days', $days));

        $instanceOf = $this->jobs->finishedBefore($before);
        $deleted = $this->jobs->pruneFinishedBefore($before);

        $logs = realpath($this->paths->logsDir());
        foreach ($deleted as $id) {
            if (false !== $logs) {
                $this->removeLog($logs, $id, $instanceOf[$id] ?? null);
            }
        }

        return \count($deleted);
    }

    private function removeLog(string $logsRoot, int $id, ?string $instanceId): void
    {
        try {
            $path = $this->paths->jobLog((string) $id, $instanceId);
        } catch (\InvalidArgumentException) {
            return; // an instance id that is not valid cannot have a log fleet wrote
        }
        if (is_link($path) || !is_file($path)) {
            return;
        }
        $real = realpath($path);
        if (false !== $real && str_starts_with($real, $logsRoot.'/')) {
            @unlink($real);
        }
    }
}
