<?php

declare(strict_types=1);

namespace App\Fleet\Jobs;

use App\Domain\Host\HostSettings;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Fleet\Shell\BackgroundProcess;
use App\Fleet\Shell\BackgroundRunner;

/**
 * The worker side of FLE-209: the one Messenger consumer (the SQLite queue takes a single one) hands each job to a child
 * process (`fleet:job:work`) instead of running it itself, so jobs of different instances overlap.
 *
 * The consumer never blocks on a job. For each RunJob message it prunes the finished children, then:
 *  - concurrency 1 (the default; no host row counts as 1): runs the job in this process, exactly as before;
 *  - the job is no longer queued, or waits for its sequential batch: JobExecutor decides, as before;
 *  - all slots are taken, or a child already works on the same instance: the message goes back on the queue with a delay
 *    and the job stays `queued` (waiting for a slot never counts against the 30 minute give-up);
 *  - otherwise a child starts. The child runs JobExecutor::run, which takes the instance lock (flock: it dies with the
 *    child, so JobReaper keeps working) and claims the job. The map of live children is only a fast pre-check; the lock is
 *    what guarantees that two jobs of one instance never overlap.
 */
final class ConcurrentJobRunner
{
    public const int WAIT_DELAY_MS = 3000;

    /** @var array<int, array{process: BackgroundProcess, key: string}> live children by job id */
    private array $children = [];

    /** @var (callable(int, ?int): BackgroundProcess)|null */
    private $spawner;

    public function __construct(
        private readonly JobExecutor $executor,
        private readonly JobRepository $jobs,
        private readonly HostSettingsRepository $settings,
        private readonly BackgroundRunner $background,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Test seam: replaces the way a child is started. The callable receives the job id and the eligibleSince clock.
     *
     * @param callable(int, ?int): BackgroundProcess $spawner
     */
    public function useSpawner(callable $spawner): void
    {
        $this->spawner = $spawner;
    }

    public function handle(int $jobId, ?int $eligibleSince): void
    {
        $limit = $this->settings->fresh()?->maxConcurrentJobs() ?? HostSettings::DEFAULT_MAX_CONCURRENT_JOBS;
        if ($limit <= 1 && [] === $this->children()) {
            $this->executor->run($jobId, $eligibleSince);

            return;
        }

        $job = $this->jobs->find($jobId);
        if (null === $job || JobStatus::Queued !== $job->status()) {
            return;
        }
        if ($this->executor->isGated($job)) {
            $this->executor->requeue($job, JobExecutor::GATED_DELAY_MS, $eligibleSince);

            return;
        }

        $children = $this->children();
        $key = JobExecutor::lockKey($job);
        $sameKey = array_filter($children, static fn (array $child): bool => $child['key'] === $key);
        if (\count($children) >= max($limit, 1) || [] !== $sameKey) {
            $this->executor->requeue($job, self::WAIT_DELAY_MS, $eligibleSince);

            return;
        }

        $process = $this->spawn($jobId, $eligibleSince);
        $this->children[$jobId] = ['process' => $process, 'key' => $key];
    }

    /** The number of children still running (after pruning the finished ones). */
    public function running(): int
    {
        return \count($this->children());
    }

    /** Waits for every child (tests, and a worker that is stopping). */
    public function wait(): void
    {
        foreach ($this->children as $child) {
            $child['process']->wait();
        }
        $this->children();
    }

    /**
     * @return array<int, array{process: BackgroundProcess, key: string}>
     */
    private function children(): array
    {
        foreach ($this->children as $id => $child) {
            if (!$child['process']->isRunning()) {
                unset($this->children[$id]);
            }
        }

        return $this->children;
    }

    private function spawn(int $jobId, ?int $eligibleSince): BackgroundProcess
    {
        if (null !== $this->spawner) {
            return ($this->spawner)($jobId, $eligibleSince);
        }

        $command = [\PHP_BINARY, $this->projectDir.'/bin/console', 'fleet:job:work', (string) $jobId, '--no-interaction'];
        if (null !== $eligibleSince) {
            $command[] = '--eligible-since='.$eligibleSince;
        }

        // The child is the app itself: it gets this process's whole environment (DATABASE_URL, FLEET_HOME, APP_ENV ...).
        $env = [];
        foreach (getenv() as $name => $value) {
            $env[(string) $name] = (string) $value;
        }

        return $this->background->start($command, $this->projectDir, $env);
    }
}
