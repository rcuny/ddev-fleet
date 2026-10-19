<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Jobs\Type;

use App\Domain\Instance\InstanceState;
use App\Fleet\Jobs\JobFailed;
use App\Fleet\Jobs\Type\StartJob;
use App\Fleet\Jobs\Type\StopJob;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;

final class StartStopJobTest extends InstanceJobTestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function start(\App\Domain\Instance\Instance $instance, array $params = []): void
    {
        $job = $this->job('start', $instance, $params);
        new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock)->execute($job, $this->logFor($job));
    }

    public function testStartsAndMarksRunning(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $dir = $this->checkout($instance);

        $this->start($instance);

        self::assertSame(['ddev start'], $this->commandNames());
        self::assertSame($dir, $this->runner->calls()[0]->cwd);
        self::assertSame(InstanceState::Running, $instance->state());
    }

    public function testMissingDirectoryFails(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);

        try {
            $this->start($instance);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertSame('instance directory not found', $e->getMessage());
        }
        self::assertSame([], $this->runner->calls());
        self::assertSame(InstanceState::Stopped, $instance->state());
    }

    public function testPortConflictRetriesOnceWithStopStart(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        $this->runner->expect(['ddev', 'start'], new CommandResult(1, '', "Error: Bind for 127.0.0.1:8080 failed: port is already allocated\n"));

        $job = $this->job('start', $instance, ['retryPortConflict' => true]);
        new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock)->execute($job, $this->logFor($job));

        self::assertSame(['ddev start', 'ddev stop', 'ddev start'], $this->commandNames());
        self::assertStringContainsString('port conflict on start - retrying with stop+start', $this->logText($job));
        self::assertSame(InstanceState::Running, $instance->state());
    }

    public function testSecondConflictFails(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        $conflict = new CommandResult(1, '', "port is already allocated\n");
        $this->runner->expect(['ddev', 'start'], $conflict)->expect(['ddev', 'stop'], new CommandResult(0))->expect(['ddev', 'start'], $conflict);

        try {
            $this->start($instance, ['retryPortConflict' => true]);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertSame('ddev start failed with exit code 1', $e->getMessage());
        }
        self::assertSame(['ddev start', 'ddev stop', 'ddev start'], $this->commandNames(), 'one retry only');
        self::assertSame(InstanceState::Stopped, $instance->state());
    }

    public function testOtherFailureDoesNotRetry(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        $this->runner->expect(['ddev', 'start'], new CommandResult(3, '', "Error: out of memory\n"));

        try {
            $this->start($instance, ['retryPortConflict' => true]);
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertSame(3, $e->exitCode);
        }
        self::assertSame(['ddev start'], $this->commandNames());
    }

    public function testRetryIsOffUnlessAsked(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        $this->runner->expect(['ddev', 'start'], new CommandResult(1, '', "port is already allocated\n"));

        try {
            $this->start($instance);
            self::fail('expected a failure');
        } catch (JobFailed) {
            self::assertSame(['ddev start'], $this->commandNames());
        }
    }

    public function testTimeoutParamReachesDdev(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);

        $this->start($instance, ['timeout' => 120.5]);

        self::assertSame(120.5, $this->runner->calls()[0]->timeout);
    }

    public function testStopsAndMarksStopped(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $this->checkout($instance);
        $job = $this->job('stop', $instance, ['timeout' => 30]);

        new StopJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock)->execute($job, $this->logFor($job));

        self::assertSame(['ddev stop'], $this->commandNames());
        self::assertSame(30.0, $this->runner->calls()[0]->timeout);
        self::assertSame(InstanceState::Stopped, $instance->state());
    }

    public function testAFailedStopLeavesTheStateAlone(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $this->checkout($instance);
        $job = $this->job('stop', $instance);
        $this->runner->expect(['ddev', 'stop'], new CommandResult(1));

        try {
            new StopJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock)->execute($job, $this->logFor($job));
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertSame('ddev stop failed with exit code 1', $e->getMessage());
        }
        self::assertSame(InstanceState::Running, $instance->state());
    }
}
