<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Domain\Job\Job;
use App\Domain\Job\JobRepository;
use App\Domain\Job\JobStatus;
use App\Fleet\Jobs\JobPruner;
use App\Tests\Support\InstanceJobTestCase;

final class JobPrunerTest extends InstanceJobTestCase
{
    private function pruner(): JobPruner
    {
        return new JobPruner(new JobRepository($this->em), $this->paths, $this->clock);
    }

    private function finished(string $type, JobStatus $status, string $ago): Job
    {
        $instance = $this->instances->find('acme--main') ?? $this->instance('main');
        $job = $this->job($type, $instance);
        $job->finish($status, 0, null, $this->clock->now()->modify($ago));
        new JobRepository($this->em)->save($job);
        $this->logFor($job)->line('hello');

        return $job;
    }

    public function testDeletesOldFinalJobsAndTheirLogs(): void
    {
        $old = $this->finished('deploy', JobStatus::Succeeded, '-91 days');
        $oldFailed = $this->finished('start', JobStatus::Failed, '-200 days');
        $recent = $this->finished('stop', JobStatus::Succeeded, '-89 days');

        self::assertSame(2, $this->pruner()->prune(90));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Job::class)->find($old->id()));
        self::assertNull($this->em->getRepository(Job::class)->find($oldFailed->id()));
        self::assertNotNull($this->em->getRepository(Job::class)->find($recent->id()));
        self::assertFileDoesNotExist($this->paths->jobLog((string) $old->id(), 'acme--main'));
        self::assertFileDoesNotExist($this->paths->jobLog((string) $oldFailed->id(), 'acme--main'));
        self::assertFileExists($this->paths->jobLog((string) $recent->id(), 'acme--main'));
    }

    public function testKeepsQueuedAndRunning(): void
    {
        // One active job per instance (uniq_job_active_instance): the queued and the running job need two instances.
        $queued = $this->job('deploy', $this->instance('main'));
        $running = $this->job('start', $this->instance('other'));
        $running->markRunning($this->clock->now()->modify('-300 days'));
        new JobRepository($this->em)->save($running);
        $this->clock->sleep(400 * 86400);

        self::assertSame(0, $this->pruner()->prune(90));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Job::class)->find($queued->id()));
        self::assertNotNull($this->em->getRepository(Job::class)->find($running->id()));
    }

    public function testNeverDeletesAFileOutsideTheLogsDir(): void
    {
        $job = $this->finished('deploy', JobStatus::Succeeded, '-120 days');
        $log = $this->paths->jobLog((string) $job->id(), 'acme--main');
        $outside = $this->paths->home().'/precious.txt';
        file_put_contents($outside, 'keep');
        unlink($log);
        symlink($outside, $log);

        self::assertSame(1, $this->pruner()->prune(90));

        self::assertFileExists($outside, 'a symlinked log is not followed');
        self::assertSame('keep', file_get_contents($outside));
    }

    public function testARetentionBelowOneDayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pruner()->prune(0);
    }
}
