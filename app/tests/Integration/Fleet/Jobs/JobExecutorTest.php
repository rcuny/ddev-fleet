<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs;

use App\Domain\Job\JobStatus;
use App\Fleet\Jobs\JobExecutor;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Jobs\RegisteredSecretsMasker;
use App\Tests\Support\JobTestCase;

final class JobExecutorTest extends JobTestCase
{
    public function testRunsAJobToSuccess(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static fn ($j, $log) => $log->line('hello'));

        $this->executor([$type])->run((int) $job->id());

        self::assertSame(JobStatus::Succeeded, $job->status());
        self::assertSame(0, $job->exitCode());
        self::assertNotNull($job->startedAt());
        self::assertNotNull($job->finishedAt());
        self::assertStringContainsString('hello', (string) file_get_contents($this->paths->jobLog((string) $job->id(), 'acme--main')));
    }

    public function testJobFailedRecordsMessageAndExitCode(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static function (): void {
            throw new JobFailed('git clone failed', 128);
        });

        $this->executor([$type])->run((int) $job->id());

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertSame(128, $job->exitCode());
        self::assertSame('git clone failed', $job->error());
    }

    public function testARegisteredSecretIsMaskedInTheLogAndInTheErrorAndForgottenAfterTheJob(): void
    {
        $masker = new RegisteredSecretsMasker();
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static function ($job, JobLog $log) use ($masker): void {
            $masker->register('tok-SECRET-42');
            $log->line('using tok-SECRET-42');

            throw new JobFailed('login with tok-SECRET-42 failed', 1);
        });

        $this->executor([$type], $masker)->run((int) $job->id());

        self::assertSame('login with [masked] failed', $job->error());
        $log = (string) file_get_contents($this->paths->jobLog((string) $job->id(), $job->instanceId()));
        self::assertStringNotContainsString('tok-SECRET-42', $log);
        self::assertStringContainsString('using [masked]', $log);
        self::assertStringContainsString('login with [masked] failed', $log);
        self::assertSame(0, $masker->longestSecret(), 'the next job starts with nothing registered');
    }

    public function testUnexpectedExceptionIsReportedWithoutItsText(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static function (): void {
            throw new \RuntimeException('password=hunter2');
        });

        $this->executor([$type])->run((int) $job->id());

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertStringContainsString('Unexpected error', (string) $job->error());
        self::assertStringNotContainsString('hunter2', (string) $job->error());
        self::assertStringNotContainsString('hunter2', (string) file_get_contents($this->paths->jobLog((string) $job->id(), 'acme--main')));
    }

    public function testASecondDeliveryDoesNothing(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static fn () => null);
        $executor = $this->executor([$type]);

        $executor->run((int) $job->id());
        $executor->run((int) $job->id());

        self::assertSame(1, $type->runs);
        self::assertSame([], $this->bus->sent);
    }

    public function testAnUnknownJobIsIgnored(): void
    {
        $this->executor([])->run(9999);

        self::assertSame([], $this->bus->sent);
    }

    public function testBusyLockRequeuesWithDelayStamp3000(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static fn () => null);
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());

        $this->executor([$type])->run((int) $job->id());

        self::assertSame(0, $type->runs);
        self::assertSame(JobStatus::Queued, $job->status());
        self::assertSame([3000], $this->bus->delays());
        $holder->release();
    }

    public function testGivesUpAfterThirtyMinutes(): void
    {
        $job = $this->queuedJob();
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());
        $this->clock->modify('+31 minutes');

        $this->executor([$this->jobType('deploy', static fn () => null)])->run((int) $job->id());

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertStringContainsString('Gave up waiting', (string) $job->error());
        self::assertSame([], $this->bus->sent);
        $holder->release();
    }

    public function testTheGiveUpWindowIsThirtyMinutesAndABusyJobStaysQueuedJustInsideIt(): void
    {
        // 1.x deliberately waits 30 minutes (docs/instances-and-jobs.md), not the 0.x 45 of FLE-27: pin the number itself.
        self::assertSame(1800, JobExecutor::GIVE_UP_SECONDS);

        $job = $this->queuedJob();
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());
        $this->clock->modify('+29 minutes');

        $this->executor([$this->jobType('deploy', static fn () => null)])->run((int) $job->id());

        self::assertSame(JobStatus::Queued, $job->status());
        self::assertSame([3000], $this->bus->delays());
        $holder->release();
    }

    public function testTheBusyRequeueCarriesTheGiveUpClockAndTheNextDeliveryUsesIt(): void
    {
        $job = $this->queuedJob();
        $created = $this->clock->now()->getTimestamp();
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());
        $executor = $this->executor([$this->jobType('deploy', static fn () => null)]);

        $executor->run((int) $job->id());

        $message = $this->bus->sent[0]->getMessage();
        self::assertInstanceOf(\App\Message\RunJob::class, $message);
        self::assertSame($created, $message->eligibleSince);

        // The carried clock decides; the database is not asked again.
        $this->clock->modify('+31 minutes');
        $executor->run((int) $job->id(), $message->eligibleSince);

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertStringContainsString('Gave up waiting', (string) $job->error());
        $holder->release();
    }

    public function testSequentialBatchWaitsForTheEarlierJob(): void
    {
        $first = $this->queuedJob('deploy', 'acme--a', 'b1', true);
        $second = $this->queuedJob('deploy', 'acme--b', 'b1', true);
        $type = $this->jobType('deploy', static fn () => null);

        $this->executor([$type])->run((int) $second->id());

        self::assertSame(0, $type->runs);
        self::assertSame(JobStatus::Queued, $second->status());
        self::assertSame([5000], $this->bus->delays());

        $this->executor([$type])->run((int) $first->id());
        $this->executor([$type])->run((int) $second->id());
        self::assertSame(2, $type->runs);
    }

    public function testFailedMemberDoesNotBlockTheNextOne(): void
    {
        $first = $this->queuedJob('deploy', 'acme--a', 'b1', true);
        $second = $this->queuedJob('deploy', 'acme--b', 'b1', true);
        $fails = $this->jobType('deploy', static function ($job): void {
            if ('acme--a' === $job->instanceId()) {
                throw new JobFailed('nope');
            }
        });
        $executor = $this->executor([$fails]);

        $executor->run((int) $first->id());
        $executor->run((int) $second->id());

        self::assertSame(JobStatus::Failed, $first->status());
        self::assertSame(JobStatus::Succeeded, $second->status());
    }

    public function testTheLockIsReleasedAfterTheFinalStatus(): void
    {
        $job = $this->queuedJob();
        $this->executor([$this->jobType('deploy', static fn () => null)])->run((int) $job->id());

        $lock = $this->locks->createLock('instance.acme--main');
        self::assertTrue($lock->acquire(false));
        $lock->release();
    }

    public function testRunInlineWaitsForTheLock(): void
    {
        $job = $this->queuedJob();
        $type = $this->jobType('deploy', static fn () => null);
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());
        $waited = 0;

        $result = $this->executor([$type])->runInline((int) $job->id(), static function () use ($holder, &$waited): void {
            ++$waited;
            $holder->release();
        });

        self::assertSame(1, $waited);
        self::assertSame(JobStatus::Succeeded, $result->status());
        self::assertSame(1, $type->runs);
    }

    public function testRunInlineGivesUpAfterThirtyMinutes(): void
    {
        $job = $this->queuedJob();
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());

        $result = $this->executor([$this->jobType('deploy', static fn () => null)])->runInline((int) $job->id(), function (): void {
            $this->clock->modify('+'.(JobExecutor::GIVE_UP_SECONDS + 1).' seconds');
        });

        self::assertSame(JobStatus::Failed, $result->status());
        $holder->release();
    }

    public function testAGatedJobDoesNotGiveUpWhileItWaitsForItsPredecessor(): void
    {
        $this->queuedJob('deploy', 'acme--a', 'b1', true);
        $second = $this->queuedJob('deploy', 'acme--b', 'b1', true);
        $this->clock->modify('+2 hours');

        $this->executor([$this->jobType('deploy', static fn () => null)])->run((int) $second->id());

        self::assertSame(JobStatus::Queued, $second->status());
        self::assertSame([5000], $this->bus->delays());
    }

    public function testTheGiveUpClockOfABatchMemberStartsWhenItsPredecessorFinished(): void
    {
        $first = $this->queuedJob('deploy', 'acme--a', 'b1', true);
        $second = $this->queuedJob('deploy', 'acme--b', 'b1', true);
        $holder = $this->locks->createLock('instance.acme--b');
        self::assertTrue($holder->acquire());

        $this->clock->modify('+40 minutes');
        $this->service->finish($first, JobStatus::Succeeded, 0, null);

        $this->clock->modify('+10 minutes');
        $this->executor([$this->jobType('deploy', static fn () => null)])->run((int) $second->id());
        self::assertSame(JobStatus::Queued, $second->status(), '10 minutes after its turn it still waits');

        $this->clock->modify('+25 minutes');
        $this->executor([$this->jobType('deploy', static fn () => null)])->run((int) $second->id());
        self::assertSame(JobStatus::Failed, $second->status());
        $holder->release();
    }

    public function testASynchronousQueueFailsFastInsteadOfRecursing(): void
    {
        $job = $this->queuedJob();
        $holder = $this->locks->createLock('instance.acme--main');
        self::assertTrue($holder->acquire());
        $executor = null;
        $bus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            public ?JobExecutor $executor = null;
            public int $calls = 0;

            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                ++$this->calls;
                \assert($message instanceof \App\Message\RunJob && null !== $this->executor);
                $this->executor->run($message->jobId); // what sync:// does: ignore the delay, handle at once

                return \Symfony\Component\Messenger\Envelope::wrap($message, $stamps);
            }
        };
        $executor = new JobExecutor(
            $this->jobs,
            new \App\Fleet\Jobs\JobTypeRegistry([]),
            $this->service,
            $this->locks,
            $bus,
            $this->paths,
            new \App\Fleet\Jobs\NoLogMasker(),
            $this->feed,
            $this->clock,
            new \Psr\Log\NullLogger(),
            0,
        );
        $bus->executor = $executor;

        $executor->run((int) $job->id());

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertStringContainsString('cannot wait', (string) $job->error());
        self::assertLessThanOrEqual(5, $bus->calls);
        $holder->release();
    }
}
