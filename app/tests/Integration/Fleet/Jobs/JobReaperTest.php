<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobStatus;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Fleet\Jobs\JobReaper;
use App\Tests\Support\JobTestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;

final class JobReaperTest extends JobTestCase
{
    private function reaper(): JobReaper
    {
        return new JobReaper($this->jobs, $this->instances, $this->service, $this->locks, $this->clock);
    }

    private function runningJobOn(string $type, InstanceState $state): \App\Domain\Job\Job
    {
        $projects = new ProjectRepository($this->em);
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $projects->save($project);
        $instance = new Instance($project, 'main', 'full', 'develop', 'admin');
        $instance->changeState($state);
        $this->instances->save($instance);

        $job = $this->queuedJob($type);
        self::assertTrue($this->jobs->claim((int) $job->id(), $this->clock->now()));

        return $job;
    }

    public function testFreeLockMeansInterrupted(): void
    {
        $job = $this->runningJobOn('deploy', InstanceState::Deploying);

        self::assertSame(1, $this->reaper()->reap());

        self::assertSame(JobStatus::Interrupted, $job->status());
        self::assertSame(InstanceState::Failed, $this->instances->find('acme--main')?->state());
    }

    public function testAStartOrStopLeavesTheInstanceUnknown(): void
    {
        $job = $this->runningJobOn('start', InstanceState::Stopped);

        $this->reaper()->reap();

        self::assertSame(JobStatus::Interrupted, $job->status());
        self::assertSame(InstanceState::Unknown, $this->instances->find('acme--main')?->state());
    }

    public function testHeldLockIsLeftAlone(): void
    {
        $job = $this->runningJobOn('deploy', InstanceState::Deploying);
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());

        self::assertSame(0, $this->reaper()->reap());

        self::assertSame(JobStatus::Running, $job->status());
        $holder->release();
    }

    public function testAJobTheExecutorFinishedMeanwhileIsNotInterrupted(): void
    {
        $job = $this->runningJobOn('deploy', InstanceState::Deploying);
        $connection = $this->em->getConnection();
        $instances = $this->instances;

        // The executor finishes the job and the instance after the reaper listed the job as running, just before the
        // reaper gets the lock (a store that does the executor's write at that moment).
        $store = new class(new FlockStore($this->paths->locksDir()), static function () use ($connection, $job): void {
            $connection->executeStatement('UPDATE job SET status = :status WHERE id = :id', ['status' => JobStatus::Succeeded->value, 'id' => $job->id()]);
            $connection->executeStatement("UPDATE instance SET state = 'running'");
        }) implements PersistingStoreInterface {
            public function __construct(private FlockStore $inner, private \Closure $beforeSave)
            {
            }

            public function save(Key $key): void
            {
                ($this->beforeSave)();
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
        $reaper = new JobReaper($this->jobs, $instances, $this->service, new LockFactory($store), $this->clock);

        self::assertSame(0, $reaper->reap());

        self::assertSame(JobStatus::Succeeded, $job->status());
        $this->em->clear();
        self::assertSame(InstanceState::Running, $instances->find('acme--main')?->state());
    }

    public function testQueuedJobsAreNotTouched(): void
    {
        $job = $this->queuedJob();

        self::assertSame(0, $this->reaper()->reap());
        self::assertSame(JobStatus::Queued, $job->status());
    }

    public function testAnOrphanedQueuedJobIsFailedAndItsInstanceReleased(): void
    {
        $projects = new ProjectRepository($this->em);
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $projects->save($project);
        $instance = new Instance($project, 'main', 'full', 'develop', 'admin');
        $instance->changeState(InstanceState::Deploying);
        $this->instances->save($instance);
        $job = $this->queuedJob();
        $this->clock->modify('+41 minutes');

        self::assertSame(1, $this->reaper()->reap());

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertStringContainsString('never started', (string) $job->error());
        self::assertSame(InstanceState::Failed, $this->instances->find('acme--main')?->state());
        self::assertSame([], $this->jobs->activeInstanceIds(['acme--main']));
    }

    public function testAnOrphanedQueuedJobTheExecutorClaimsMeanwhileIsNotFailed(): void
    {
        $job = $this->queuedJob();
        $this->clock->modify('+41 minutes');
        $connection = $this->em->getConnection();

        // The executor claims the job after the reaper listed it as stale, just before the reaper gets the lock.
        $store = new class(new FlockStore($this->paths->locksDir()), static function () use ($connection, $job): void {
            $connection->executeStatement('UPDATE job SET status = :status WHERE id = :id', ['status' => JobStatus::Running->value, 'id' => $job->id()]);
        }) implements PersistingStoreInterface {
            public function __construct(private FlockStore $inner, private \Closure $beforeSave)
            {
            }

            public function save(Key $key): void
            {
                ($this->beforeSave)();
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
        $reaper = new JobReaper($this->jobs, $this->instances, $this->service, new LockFactory($store), $this->clock);

        self::assertSame(0, $reaper->reap());

        self::assertSame(JobStatus::Running, $job->status());
    }

    public function testAnOrphanedQueuedJobWhoseInstanceLockIsHeldIsLeftAlone(): void
    {
        $job = $this->queuedJob();
        $this->clock->modify('+41 minutes');
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());

        self::assertSame(0, $this->reaper()->reap());

        self::assertSame(JobStatus::Queued, $job->status());
        $holder->release();
    }

    public function testAQueuedJobStillInItsGiveUpWindowIsLeftAlone(): void
    {
        $job = $this->queuedJob();
        $this->clock->modify('+31 minutes');

        self::assertSame(0, $this->reaper()->reap());
        self::assertSame(JobStatus::Queued, $job->status());
    }

    public function testAQueuedBatchMemberWaitingForAnActivePredecessorIsLeftAlone(): void
    {
        $this->queuedJob('deploy', 'acme--a', 'b1', true);
        $second = $this->queuedJob('deploy', 'acme--b', 'b1', true);
        $this->clock->modify('+3 hours');

        // The first member is the orphan; the second waits for it and is judged on its own once it is gone.
        self::assertSame(1, $this->reaper()->reap());
        self::assertSame(JobStatus::Queued, $second->status());
    }
}
