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

    /** @var list<string> */
    private array $gates = [];

    protected function tearDown(): void
    {
        foreach ($this->gates as $gate) {
            @touch($gate);
            @unlink($gate);
        }
        parent::tearDown();
    }

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

    public function testConcurrencyOneIgnoresARunningJobOfAnotherProcess(): void
    {
        // FLE-214: with a sync:// transport every request is its own "worker"; a neighbour's running job must not make this one wait.
        $other = $this->queuedJob('deploy', 'a--main');
        self::assertTrue($this->jobs->claim((int) $other->id(), $this->clock->now()));
        $job = $this->queuedJob('deploy', 'b--main');
        $spawned = [];
        $runner = $this->runner($this->jobType('deploy', static fn () => null), $spawned);

        $runner->handle((int) $job->id(), null);

        self::assertSame(JobStatus::Succeeded, $this->fresh($job)->status());
        self::assertSame([], $this->bus->delays(), 'not re-queued');
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
        $runner = $this->realRunner();
        $gate = $this->gateFile();
        $jobs = [];
        foreach (['a--main', 'b--main', 'c--main', 'd--main'] as $instance) {
            $jobs[] = $this->fakeJob($instance, $gate);
        }

        // Three start; the fourth finds no slot (a gated job holds each) and goes back on the queue.
        foreach ($jobs as $job) {
            $runner->handle((int) $job->id(), null);
        }
        self::assertTrue($this->waitFor(fn (): bool => 3 === $this->countStatus($jobs, JobStatus::Running)), 'three instances run together');
        self::assertSame(JobStatus::Queued, $this->fresh($jobs[3])->status(), 'never more than 3 at once');

        touch($gate);
        $runner->wait();
        self::assertTrue($this->waitFor(fn (): bool => 3 === $this->countStatus($jobs, JobStatus::Succeeded)));
        $runner->handle((int) $jobs[3]->id(), null);
        $runner->wait();
        self::assertTrue($this->waitFor(fn (): bool => 4 === $this->countStatus($jobs, JobStatus::Succeeded)), 'the fourth ran once a slot freed');
    }

    public function testAChildOutlivesTheRunnerThatStartedIt(): void
    {
        $this->setLimit(2);
        $gate = $this->gateFile();
        $job = $this->fakeJob('a--main', $gate);

        $runner = $this->realRunner();
        $runner->handle((int) $job->id(), null);
        self::assertTrue($this->waitFor(fn (): bool => JobStatus::Running === $this->fresh($job)->status()));

        // The worker leaves (time-limit, restart): the Process objects are destroyed; the child must not be killed.
        $runner = null;
        gc_collect_cycles();
        touch($gate);

        self::assertTrue($this->waitFor(fn (): bool => JobStatus::Succeeded === $this->fresh($job)->status()), 'the job finished');
    }

    public function testASurvivingChildOfAStoppedWorkerStillCountsAgainstTheLimitAndItsInstance(): void
    {
        $this->setLimit(2);
        $gate = $this->gateFile();
        $running = $this->fakeJob('a--main', $gate);
        $this->realRunner()->handle((int) $running->id(), null);
        self::assertTrue($this->waitFor(fn (): bool => JobStatus::Running === $this->fresh($running)->status()));

        // A second runner (the restarted worker) has no children of its own.
        $fresh = $this->realRunner();
        self::assertSame(1, $fresh->running());
        $other = $this->fakeJob('b--main', $gate);
        $fresh->handle((int) $other->id(), null);
        self::assertTrue($this->waitFor(static fn (): bool => 2 === $fresh->running()), 'another instance gets the free slot');

        $third = $this->fakeJob('c--main', $gate);
        $fresh->handle((int) $third->id(), null);
        self::assertSame(JobStatus::Queued, $this->fresh($third)->status());
        self::assertContains(ConcurrentJobRunner::WAIT_DELAY_MS, $this->bus->delays(), 'the limit counts the survivors');

        touch($gate);
        $fresh->wait();
        self::assertTrue($this->waitFor(static fn (): bool => 0 === $fresh->running()));
    }

    public function testAChildThatDiesBeforeClaimingRequeuesItsJobAtOnce(): void
    {
        $this->setLimit(2);
        $job = $this->queuedJob('deploy', 'a--main');
        $runner = new ConcurrentJobRunner($this->executor([]), $this->jobs, $this->settings, new ProcessCommandRunner(), \dirname(__DIR__, 4), $this->clock);
        $runner->useSpawner(static fn (): BackgroundProcess => new class implements BackgroundProcess {
            private Process $process;

            public function __construct()
            {
                $this->process = new Process(['sh', '-c', 'exit 3']);
                $this->process->start();
            }

            public function isRunning(): bool
            {
                return $this->process->isRunning();
            }

            public function exitCode(): ?int
            {
                return $this->process->isRunning() ? null : $this->process->getExitCode();
            }

            public function wait(): void
            {
                $this->process->wait();
            }
        });

        $runner->handle((int) $job->id(), null);
        $runner->wait();

        self::assertSame(JobStatus::Queued, $job->status());
        self::assertSame([1000], $this->bus->delays(), 'the dead child\'s job is back on the queue, not left for the reaper');
    }

    public function testAStartedButUnclaimedChildCountsForARestartedWorkerAndIsRecoveredWhenLost(): void
    {
        $this->setLimit(2);
        $job = $this->queuedJob('deploy', 'a--main');
        $spawned = [];
        $first = new ConcurrentJobRunner($this->executor([]), $this->jobs, $this->settings, new RecordingCommandRunner(), \dirname(__DIR__, 4), $this->clock);
        $first->useSpawner(static function (int $id) use (&$spawned): BackgroundProcess {
            $spawned[] = $id;

            return new class implements BackgroundProcess {
                public function isRunning(): bool
                {
                    return true;
                }

                public function exitCode(): ?int
                {
                    return null;
                }

                public function wait(): void
                {
                }
            };
        });
        $first->handle((int) $job->id(), null);
        self::assertSame([(int) $job->id()], $spawned);
        self::assertNotNull($this->fresh($job)->spawnedAt(), 'the job is stamped before the child starts');

        // The worker restarts: the new one knows no children, but the stamp keeps the slot (and the instance) taken.
        $fresh = $this->realRunner();
        self::assertSame(1, $fresh->running(), 'a spawned, unclaimed child is visible');
        $fresh->useSpawner(static fn (): BackgroundProcess => new class implements BackgroundProcess {
            public function isRunning(): bool
            {
                return true;
            }

            public function exitCode(): ?int
            {
                return null;
            }

            public function wait(): void
            {
            }
        });
        $fresh->handle((int) $this->queuedJob('deploy', 'b--main')->id(), null);
        self::assertSame(2, $fresh->running());
        $fresh->handle((int) $this->queuedJob('deploy', 'c--main')->id(), null);
        self::assertSame([ConcurrentJobRunner::WAIT_DELAY_MS], $this->bus->delays(), 'the limit does not overshoot');

        // Its child never claims the job: after the grace period it is queued again at once.
        $this->clock->sleep(ConcurrentJobRunner::SPAWN_GRACE_SECONDS + 1);
        $fresh->running();
        self::assertNull($this->fresh($job)->spawnedAt());
        self::assertContains(1000, $this->bus->delays(), 'the lost child\'s job is back on the queue');
    }

    private function realRunner(): ConcurrentJobRunner
    {
        return new ConcurrentJobRunner($this->executor([]), $this->jobs, $this->settings, new ProcessCommandRunner(), \dirname(__DIR__, 4), $this->clock);
    }

    private function gateFile(): string
    {
        $gate = sys_get_temp_dir().'/fle209-gate-'.bin2hex(random_bytes(6));
        $this->gates[] = $gate;

        return $gate;
    }

    private function fakeJob(string $instance, string $gate): Job
    {
        $job = new Job('fake', $instance, 'acme', JobTrigger::Cli, 'test', ['lines' => 3, 'delay' => 0, 'width' => 0, 'fail' => false, 'gate' => $gate], null, false, null, $this->clock->now());
        $this->jobs->add($job);

        return $job;
    }

    private function fresh(Job $job): Job
    {
        $this->jobs->refresh($job);

        return $job;
    }

    /** @param list<Job> $jobs */
    private function countStatus(array $jobs, JobStatus $status): int
    {
        return \count(array_filter($jobs, fn (Job $j): bool => $this->fresh($j)->status() === $status));
    }

    private function waitFor(callable $condition, int $seconds = 60): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(50_000);
        }

        return false;
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
        $runner = new ConcurrentJobRunner($this->executor([$type]), $this->jobs, $this->settings, new RecordingCommandRunner(), \dirname(__DIR__, 4), $this->clock);
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

                public function exitCode(): ?int
                {
                    return $this->process->isRunning() ? null : $this->process->getExitCode();
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
