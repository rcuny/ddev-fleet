<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Fleet\Jobs\ConcurrentJobRunner;
use App\Fleet\Shell\BackgroundProcess;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Message\RunJob;
use App\Tests\Support\JobTestCase;
use Symfony\Component\Process\Process;

/**
 * FLE-209: the worker hands jobs to child processes. The first group fakes the children (`sleep`) to pin the scheduling
 * rules; the second runs real `fleet:job:work` children against the real SQLite file.
 */
final class ConcurrentJobRunnerTest extends JobTestCase
{
    private HostSettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new HostSettingsRepository($this->em);
    }

    public function testConcurrencyOneRunsTheJobInTheWorkerItself(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static fn () => null);
        $spawned = [];
        $runner = $this->runner($type, $spawned);

        $runner->handle((int) $job->id(), null);

        self::assertSame(JobStatus::Succeeded, $job->status());
        self::assertSame([], $spawned, 'no child at concurrency 1');
    }

    public function testDifferentInstancesGetOneChildEachUpToTheLimitAndTheRestWaits(): void
    {
        $this->setLimit(2);
        $spawned = [];
        $runner = $this->runner($this->jobType('deploy', static fn () => null), $spawned);
        $a = $this->queuedJob('deploy', 'a--main');
        $b = $this->queuedJob('deploy', 'b--main');
        $c = $this->queuedJob('deploy', 'c--main');

        foreach ([$a, $b, $c] as $job) {
            $runner->handle((int) $job->id(), null);
        }

        self::assertSame([(int) $a->id(), (int) $b->id()], $spawned);
        self::assertSame(2, $runner->running());
        self::assertSame([ConcurrentJobRunner::WAIT_DELAY_MS], $this->bus->delays(), 'the third goes back on the queue');
        self::assertSame(JobStatus::Queued, $c->status());

        $runner->wait();
        self::assertSame(0, $runner->running());
        $runner->handle((int) $c->id(), null);
        self::assertSame([(int) $a->id(), (int) $b->id(), (int) $c->id()], $spawned, 'a slot freed: the third starts');
    }

    public function testTwoJobsOfOneInstanceNeverRunTogether(): void
    {
        $this->setLimit(3);
        $spawned = [];
        $runner = $this->runner($this->jobType('deploy', static fn () => null), $spawned);
        // The database allows one active job per instance; jobs without an instance share the lock 'instance.fleet'.
        $first = $this->queuedJob('deploy', null);
        $second = $this->queuedJob('restart', null);

        $runner->handle((int) $first->id(), null);
        $runner->handle((int) $second->id(), null);

        self::assertSame([(int) $first->id()], $spawned);
        self::assertSame([ConcurrentJobRunner::WAIT_DELAY_MS], $this->bus->delays());
    }

    public function testAJobThatIsNoLongerQueuedIsIgnoredAndASequentialSuccessorWaits(): void
    {
        $this->setLimit(3);
        $spawned = [];
        $runner = $this->runner($this->jobType('deploy', static fn () => null), $spawned);
        $done = $this->queuedJob();
        $this->service->finish($done, JobStatus::Succeeded, 0, null);
        $first = $this->queuedJob('deploy', 'a--main', 'batch-1', true);
        $second = $this->queuedJob('deploy', 'b--main', 'batch-1', true);

        $runner->handle((int) $done->id(), null);
        $runner->handle((int) $second->id(), 123);

        self::assertSame([], $spawned);
        self::assertSame([5000], $this->bus->delays(), 'gated: the batch delay, no child');
        $message = $this->bus->sent[0]->getMessage();
        self::assertInstanceOf(RunJob::class, $message);
        self::assertSame(123, $message->eligibleSince);
        self::assertNotNull($first->id());
    }

    public function testTheLimitIsReadFreshSoAChangeReachesALongRunningWorker(): void
    {
        $spawned = [];
        $runner = $this->runner($this->jobType('deploy', static fn () => null), $spawned);
        $a = $this->queuedJob('deploy', 'a--main');
        $b = $this->queuedJob('deploy', 'b--main');

        $runner->handle((int) $a->id(), null); // limit 1: inline
        $this->em->getConnection()->executeStatement('UPDATE host_settings SET max_concurrent_jobs = 4');
        $runner->handle((int) $b->id(), null);

        self::assertSame([(int) $b->id()], $spawned);
    }

    public function testRealChildrenOverlapAcrossInstancesUpToTheLimit(): void
    {
        $this->setLimit(3);
        $runner = new ConcurrentJobRunner($this->executor([]), $this->jobs, $this->settings, new ProcessCommandRunner(), \dirname(__DIR__, 4));
        // Four instances at limit 3, ~1.2 s per job, 40 log lines (DB writes) each: the fourth waits for a free slot.
        $jobs = [];
        foreach (['a--main', 'b--main', 'c--main', 'd--main'] as $instance) {
            $job = new Job('fake', $instance, 'acme', JobTrigger::Cli, 'test', ['lines' => 40, 'delay' => 30, 'width' => 0, 'fail' => false], null, false, null, $this->clock->now());
            $this->jobs->add($job);
            $jobs[] = $job;
        }

        $deadline = microtime(true) + 60;
        $pending = array_map(static fn (Job $j): int => (int) $j->id(), $jobs);
        while ([] !== $pending && microtime(true) < $deadline) {
            foreach ($pending as $i => $id) {
                $job = $this->jobs->find($id);
                self::assertNotNull($job);
                $this->jobs->refresh($job);
                if (JobStatus::Queued === $job->status()) {
                    $before = $runner->running();
                    $runner->handle($id, null);
                    if ($runner->running() > $before) {
                        unset($pending[$i]);
                    }
                } else {
                    unset($pending[$i]);
                }
            }
            usleep(100_000);
        }
        self::assertSame([], $pending, 'every job was started');
        $runner->wait();

        $ranges = [];
        foreach ($jobs as $job) {
            $this->jobs->refresh($job);
            self::assertSame(JobStatus::Succeeded, $job->status(), (string) $job->error());
            $ranges[] = [$job->instanceId(), (float) $job->startedAt()?->format('U.u'), (float) $job->finishedAt()?->format('U.u')];
        }
        $overlap = static fn (array $x, array $y): bool => $x[1] < $y[2] && $y[1] < $x[2];
        self::assertTrue($overlap($ranges[0], $ranges[1]) && $overlap($ranges[1], $ranges[2]), 'three instances ran together');
        $firstFinish = min($ranges[0][2], $ranges[1][2], $ranges[2][2]);
        self::assertGreaterThanOrEqual($firstFinish - 0.05, $ranges[3][1], 'the fourth waited for a slot: never more than 3 at once');
    }

    private function setLimit(int $jobs): void
    {
        $host = $this->settings->getOrCreate('fleet.test', $this->clock->now());
        $this->em->getConnection()->executeStatement('UPDATE host_settings SET max_concurrent_jobs = '.$jobs);
        $this->em->refresh($host);
    }

    /**
     * @param list<int> $spawned filled with the id of each job a child was started for
     */
    private function runner(\App\Tests\Support\CountingJobType $type, array &$spawned): ConcurrentJobRunner
    {
        $this->settings->getOrCreate('fleet.test', $this->clock->now());
        $runner = new ConcurrentJobRunner($this->executor([$type]), $this->jobs, $this->settings, new RecordingCommandRunner(), \dirname(__DIR__, 4));
        $runner->useSpawner(static function (int $id) use (&$spawned): BackgroundProcess {
            $spawned[] = $id;
            $process = new Process(['sleep', '0.3']);
            $process->start();

            return new class($process) implements BackgroundProcess {
                public function __construct(private readonly Process $process)
                {
                }

                public function isRunning(): bool
                {
                    return $this->process->isRunning();
                }

                public function wait(): void
                {
                    $this->process->wait();
                }
            };
        });

        return $runner;
    }
}
