<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Instance;

use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobRepository;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceCliTestCase;

final class StartCommandTest extends InstanceCliTestCase
{
    public function testSequentialTimeoutAndRetryFlagsReachTheJob(): void
    {
        $this->instances([InstanceState::Stopped, InstanceState::Stopped]);

        $tester = $this->command('fleet:instance:start', ['id' => ['acme--i1', 'acme--i2'], '--sequential' => true, '--timeout' => '120', '--retry-port-conflict' => true, '--detach' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->em->clear();
        $jobs = self::getContainer()->get(JobRepository::class);
        self::assertInstanceOf(JobRepository::class, $jobs);
        $rows = $jobs->search([], 1)['rows'];
        self::assertCount(2, $rows);
        foreach ($rows as $job) {
            self::assertSame('start', $job->type());
            self::assertTrue($job->isSequential());
            self::assertSame(['timeout' => 120.0, 'retryPortConflict' => true], $job->params());
        }
    }

    public function testABadTimeoutExitsOne(): void
    {
        $this->instances([InstanceState::Stopped]);

        $tester = $this->command('fleet:instance:start', ['id' => ['acme--i1'], '--timeout' => 'soon']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--timeout: ', $tester->getErrorOutput());
    }

    public function testAllSkipsDeployingAndFailed(): void
    {
        $this->instances([InstanceState::Stopped, InstanceState::Deploying, InstanceState::Failed, InstanceState::Destroying]);

        $tester = $this->command('fleet:instance:start', ['--all' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 succeeded, 0 failed', $tester->getDisplay());
        self::assertCount(1, $this->runner()->callsMatching(['ddev', 'start']));
        self::assertSame(InstanceState::Deploying, $this->stateOf('acme--i2'));
    }

    public function testAllWithNothingToStart(): void
    {
        $this->instances([InstanceState::Deploying]);

        $tester = $this->command('fleet:instance:start', ['--all' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('nothing to start', $tester->getDisplay());
    }

    public function testMixedResultExitsTwo(): void
    {
        $this->instances([InstanceState::Stopped, InstanceState::Stopped]);
        $this->runner()->expect(['ddev', 'start'], new CommandResult(1, '', 'boom'));

        $tester = $this->command('fleet:instance:start', ['--all' => true]);

        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('1 succeeded, 1 failed', $tester->getDisplay());
    }

    public function testStopStopsRunningInstances(): void
    {
        $this->instances([InstanceState::Running]);

        $tester = $this->command('fleet:instance:stop', ['id' => ['acme--i1']]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(InstanceState::Stopped, $this->stateOf('acme--i1'));
    }
}
