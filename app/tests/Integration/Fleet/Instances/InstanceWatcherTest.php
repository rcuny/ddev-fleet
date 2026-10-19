<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\JobStatus;
use App\Fleet\Instances\InstanceWatcher;
use App\Fleet\Jobs\JobPruner;
use App\Fleet\Jobs\JobReaper;
use App\Fleet\Shell\CommandResult;
use App\Tests\Support\InstanceJobTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class InstanceWatcherTest extends InstanceJobTestCase
{
    /** @var list<string> */
    private array $published = [];

    private function watcher(): InstanceWatcher
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update->getTopics()[0].' '.$update->getData();

            return 'id';
        });
        $feed = new \App\Fleet\Jobs\JobFeed($hub, new \Psr\Log\NullLogger());
        $jobs = new \App\Domain\Job\JobRepository($this->em);
        $service = new \App\Fleet\Jobs\JobService($jobs, new \App\Tests\Support\RecordingMessageBus(), $feed, new \App\Fleet\Jobs\LogReader($this->paths), $this->clock, new \Psr\Log\NullLogger());
        $locks = new LockFactory(new FlockStore($this->paths->locksDir()));

        return new InstanceWatcher(
            $this->instances,
            $this->ddev,
            new JobReaper($jobs, $this->instances, $service, $locks, $this->clock),
            new JobPruner($jobs, $this->paths, $this->clock),
            $feed,
            90,
        );
    }

    /**
     * @param array<string, string> $statuses project name => ddev status
     */
    private function ddevList(array $statuses): CommandResult
    {
        $rows = [];
        foreach ($statuses as $name => $status) {
            $rows[] = ['name' => $name, 'status' => $status, 'approot' => '/srv/fleet/instances/'.$name];
        }

        return new CommandResult(0, json_encode(['level' => 'info', 'msg' => 'listed', 'raw' => $rows], \JSON_THROW_ON_ERROR)."\n");
    }

    private function stateOf(Instance $instance): InstanceState
    {
        $this->em->clear();

        return $this->instances->find($instance->id())?->state() ?? self::fail('the instance vanished');
    }

    public function testRunningAndStoppedFromDdevList(): void
    {
        $a = $this->instance('a', 'develop', InstanceState::Stopped);
        $b = $this->instance('b', 'develop', InstanceState::Running);
        $c = $this->instance('c', 'develop', InstanceState::Running);
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running', 'acme--b' => 'stopped', 'acme--c' => 'paused', 'acme--other' => 'running']));

        $changed = $this->watcher()->tick($this->clock->now());

        self::assertSame(3, $changed);
        self::assertSame(InstanceState::Running, $this->stateOf($a));
        self::assertSame(InstanceState::Stopped, $this->stateOf($b));
        self::assertSame(InstanceState::Stopped, $this->stateOf($c));
        self::assertNull($this->instances->find('acme--other'), 'a ddev project without a row is ignored');
        self::assertNotNull($this->instances->find('acme--a')?->lastObservedAt());
    }

    public function testListFailureMakesThemUnknown(): void
    {
        $a = $this->instance('a', 'develop', InstanceState::Running);
        $b = $this->instance('b', 'develop', InstanceState::Stopped);
        $this->runner->expect(['ddev', 'list'], new CommandResult(1, '', 'docker is down'));

        $this->watcher()->tick($this->clock->now());

        self::assertSame(InstanceState::Unknown, $this->stateOf($a));
        self::assertSame(InstanceState::Unknown, $this->stateOf($b), 'never stopped when ddev does not answer');
    }

    public function testDoesNotOverwriteDeployingOrFailed(): void
    {
        $deploying = $this->instance('a', 'develop', InstanceState::Deploying);
        $destroying = $this->instance('b', 'develop', InstanceState::Destroying);
        $failed = $this->instance('c', 'develop', InstanceState::Failed);
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running', 'acme--b' => 'running', 'acme--c' => 'running']));

        self::assertSame(0, $this->watcher()->tick($this->clock->now()));

        self::assertSame(InstanceState::Deploying, $this->stateOf($deploying));
        self::assertSame(InstanceState::Destroying, $this->stateOf($destroying));
        self::assertSame(InstanceState::Failed, $this->stateOf($failed));
    }

    public function testRamIsStored(): void
    {
        $a = $this->instance('a', 'develop', InstanceState::Running);
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running']));
        $this->runner->expect(['docker', 'stats'], new CommandResult(0, json_encode(['Name' => 'ddev-acme--a-web', 'MemUsage' => '300MiB / 8GiB'])."\n".json_encode(['Name' => 'ddev-acme--a-db', 'MemUsage' => '212MiB / 8GiB'])."\n"));

        $this->watcher()->tick($this->clock->now());

        $this->em->clear();
        self::assertSame(512, $this->instances->find($a->id())?->ramMib());
    }

    public function testReapsDeadJobsEachTick(): void
    {
        $instance = $this->instance('a', 'develop', InstanceState::Running);
        $job = $this->job('start', $instance);
        $job->markRunning($this->clock->now());
        new \App\Domain\Job\JobRepository($this->em)->save($job);
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running']));

        $this->watcher()->tick($this->clock->now());

        $this->em->clear();
        self::assertSame(JobStatus::Interrupted, $this->em->getRepository(\App\Domain\Job\Job::class)->find($job->id())?->status());
    }

    public function testPublishesOnlyOnChange(): void
    {
        $this->instance('a', 'develop', InstanceState::Stopped);
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running']));
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running']));
        $watcher = $this->watcher();

        $watcher->tick($this->clock->now());
        $instanceUpdates = fn (): int => \count(array_filter($this->published, static fn (string $p): bool => str_starts_with($p, '/instances ')));
        self::assertSame(1, $instanceUpdates());

        $this->clock->sleep(11);
        self::assertSame(0, $watcher->tick($this->clock->now()));
        self::assertSame(1, $instanceUpdates(), 'nothing changed, nothing published');
    }

    public function testLanesAreRateLimited(): void
    {
        $this->instance('a', 'develop', InstanceState::Running);
        $watcher = $this->watcher();

        $watcher->tick($this->clock->now());
        $this->clock->sleep(5);
        $watcher->tick($this->clock->now());
        self::assertCount(1, $this->runner->callsMatching(['ddev', 'list']));

        $this->clock->sleep(6);
        $watcher->tick($this->clock->now());
        self::assertCount(2, $this->runner->callsMatching(['ddev', 'list']));
        self::assertCount(1, $this->runner->callsMatching(['docker', 'stats']), 'memory every 30 seconds');
    }
}
