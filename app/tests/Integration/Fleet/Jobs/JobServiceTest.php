<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Domain\Job\JobStatus;
use App\Domain\Job\JobTrigger;
use App\Fleet\Projects\Refused;
use App\Message\RunJob;
use App\Tests\Support\JobTestCase;

final class JobServiceTest extends JobTestCase
{
    public function testEnqueuePersistsThenDispatches(): void
    {
        $job = new \App\Domain\Job\Job('deploy', 'acme--main', 'acme', JobTrigger::Ui, 'admin');

        $this->service->enqueue($job);

        self::assertNotNull($job->id());
        self::assertSame(JobStatus::Queued, $this->jobs->find((int) $job->id())?->status());
        self::assertCount(1, $this->bus->sent);
        $message = $this->bus->sent[0]->getMessage();
        self::assertInstanceOf(RunJob::class, $message);
        self::assertSame($job->id(), $message->jobId);
    }

    public function testDispatchFailureMarksTheJobFailed(): void
    {
        $this->bus->failWith = new \RuntimeException('queue is down: secret detail');
        $job = new \App\Domain\Job\Job('deploy', 'acme--main', 'acme', JobTrigger::Ui, 'admin');

        $this->service->enqueue($job);

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertSame('The job could not be queued.', $job->error());
        self::assertNotNull($job->finishedAt());
    }

    public function testRetryLinksTheOriginal(): void
    {
        $failed = $this->queuedJob('start');
        $this->service->finish($failed, JobStatus::Failed, 1, 'boom');

        $retry = $this->service->retry($failed, 'cli:alice', JobTrigger::Cli);

        self::assertSame('start', $retry->type());
        self::assertSame($failed->id(), $retry->retryOf());
        self::assertSame('acme--main', $retry->instanceId());
        self::assertSame(JobTrigger::Cli, $retry->trigger());
        self::assertSame('cli:alice', $retry->requestedBy());
        self::assertSame(JobStatus::Queued, $retry->status());
        self::assertNotNull($retry->id());
    }

    public function testRetryOfADeployIsARedeploy(): void
    {
        $failed = $this->queuedJob('deploy');
        $this->service->finish($failed, JobStatus::Failed, 1, 'boom');

        self::assertSame('redeploy', $this->service->retry($failed, 'admin', JobTrigger::Ui)->type());
    }

    public function testOnlyAFailedJobCanBeRetried(): void
    {
        $this->expectException(Refused::class);

        $this->service->retry($this->queuedJob(), 'admin', JobTrigger::Ui);
    }
}
