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

    public function testADispatchFailureIsLoggedWithTheException(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $service = new \App\Fleet\Jobs\JobService($this->jobs, $this->bus, $this->feed, $this->reader, $this->clock, $logger);
        $this->bus->failWith = new \RuntimeException('queue is down');

        $service->enqueue(new \App\Domain\Job\Job('deploy', 'acme--main', 'acme', JobTrigger::Ui, 'admin'));

        self::assertCount(1, $logger->records);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0][1]['exception']);
    }

    public function testADispatchFailureDoesNotOverwriteAJobThatAlreadyRan(): void
    {
        $job = new \App\Domain\Job\Job('deploy', 'acme--main', 'acme', JobTrigger::Ui, 'admin');
        $bus = new class($this->jobs, $this->service) implements \Symfony\Component\Messenger\MessageBusInterface {
            public function __construct(private readonly \App\Domain\Job\JobRepository $jobs, private readonly \App\Fleet\Jobs\JobService $service)
            {
            }

            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                \assert($message instanceof RunJob);
                $this->jobs->claim($message->jobId, new \DateTimeImmutable());
                $job = $this->jobs->find($message->jobId);
                \assert(null !== $job);
                $this->service->finish($job, JobStatus::Succeeded, 0, null); // the sync:// handler ran it ...
                throw new \RuntimeException('... and something later in the chain threw');
            }
        };
        $service = new \App\Fleet\Jobs\JobService($this->jobs, $bus, $this->feed, $this->reader, $this->clock, new \Psr\Log\NullLogger());

        $service->enqueue($job);

        self::assertSame(JobStatus::Succeeded, $job->status());
        self::assertNull($job->error());
    }
}
