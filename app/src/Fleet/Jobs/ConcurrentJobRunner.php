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
use Psr\Log\LoggerInterface;

/**
 * The worker side of FLE-209: the one Messenger consumer (the SQLite queue takes a single one) hands each job to a child
 * process (`fleet:job:work`) instead of running it itself, so jobs of different instances overlap.
 *
 * Children are detached (DetachedProcess): they outlive the worker (time-limit, restart, upgrade). A new worker does not
 * know them, so the jobs with status `running` count as busy slots too (a `running` job always has a live lock holder,
 * JobReaper keeps it so). A child that ends non-zero while its job is still `queued` (it died before claiming) is retried
 * at once, within the usual 30 minute give-up.
 *
 * The consumer never blocks on a job. For each RunJob message it prunes the finished children, then:
 *  - concurrency 1 (the default; no host row counts as 1) and no child of this process running: runs the job in this process,
 *    exactly as before (the instance lock alone serialises jobs of one instance);
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

    /** @var array<int, array{process: BackgroundProcess, key: string, since: ?int}> children this process started, by job id */
    private array $children = [];

    /** @var (callable(int, ?int): BackgroundProcess)|null */
    private $spawner;

    public function __construct(
        private readonly JobExecutor $executor,
        private readonly JobRepository $jobs,
        private readonly HostSettingsRepository $settings,
        private readonly BackgroundRunner $background,
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
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
        if ($limit <= 1 && [] === $this->ownChildren()) {
            // Jobs of other processes that are `running` are not counted here: at the default limit the instance lock is the only
            // guard (as before FLE-209). Counting them made every request of a `sync://` transport (dev, test: one request per
            // click, several at once) re-queue behind its neighbour's job, which cannot wait there, and fail (FLE-214).
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

        $busy = $this->busy();
        $key = JobExecutor::lockKey($job);
        if (\count($busy) >= max($limit, 1) || \in_array($key, $busy, true)) {
            $this->executor->requeue($job, self::WAIT_DELAY_MS, $eligibleSince);

            return;
        }

        $process = $this->spawn($jobId, $eligibleSince);
        $this->children[$jobId] = ['process' => $process, 'key' => $key, 'since' => $eligibleSince];
    }

    /** The number of children still running (after pruning the finished ones). */
    public function running(): int
    {
        return \count($this->busy());
    }

    /** Waits for every child (tests, and a worker that is stopping). */
    public function wait(): void
    {
        foreach ($this->children as $child) {
            $child['process']->wait();
        }
        $this->busy();
    }

    /**
     * The children this process started and that still run (prunes the finished ones).
     *
     * @return array<int, string> lock key by job id
     */
    private function ownChildren(): array
    {
        $own = [];
        foreach ($this->children as $id => $child) {
            if ($child['process']->isRunning()) {
                $own[$id] = $child['key'];
            }
        }
        $this->busy();

        return $own;
    }

    /**
     * The lock keys of the jobs running now, one entry per job: the children this process started plus the `running`
     * jobs of children it does not know (started by a worker that has since stopped). Prunes the finished children.
     *
     * @return array<int, string> lock key by job id
     */
    private function busy(): array
    {
        foreach ($this->children as $id => $child) {
            if ($child['process']->isRunning()) {
                continue;
            }
            unset($this->children[$id]);
            $this->afterExit($id, $child['process']->exitCode(), $child['since']);
        }

        $busy = [];
        foreach ($this->children as $id => $child) {
            $busy[$id] = $child['key'];
        }
        foreach ($this->jobs->running() as $job) {
            $busy[(int) $job->id()] ??= JobExecutor::lockKey($job);
        }

        return $busy;
    }

    /** A child that ended without a clean exit and left its job `queued` never claimed it: try again now. */
    private function afterExit(int $jobId, ?int $exitCode, ?int $since): void
    {
        if (0 === $exitCode) {
            return;
        }
        $this->logger?->warning('Job {id}: its child process ended with exit code {code}.', ['id' => $jobId, 'code' => $exitCode ?? 'signal']);
        $job = $this->jobs->find($jobId);
        if (null === $job) {
            return;
        }
        $this->jobs->refresh($job);
        if (JobStatus::Queued === $job->status()) {
            $this->executor->requeueOrGiveUp($job, 1000, $since);
        }
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
