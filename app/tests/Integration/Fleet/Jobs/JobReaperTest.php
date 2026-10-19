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

    public function testQueuedJobsAreNotTouched(): void
    {
        $job = $this->queuedJob();

        self::assertSame(0, $this->reaper()->reap());
        self::assertSame(JobStatus::Queued, $job->status());
    }
}
