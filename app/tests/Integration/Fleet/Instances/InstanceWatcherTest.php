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

    private function watcher(bool $withAgent = false, bool $withIsolation = false, ?\Psr\Log\LoggerInterface $logger = null): InstanceWatcher
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update->getTopics()[0].' '.$update->getData();

            return 'id';
        });
        $feed = new \App\Fleet\Jobs\JobFeed($hub, new \Psr\Log\NullLogger());
        $jobs = new \App\Domain\Job\JobRepository($this->em);
        $service = new \App\Fleet\Jobs\JobService($jobs, new \App\Tests\Support\RecordingMessageBus(), $feed, new \App\Fleet\Jobs\LogReader($this->paths), $this->clock, new \Psr\Log\NullLogger(), new \Symfony\Component\EventDispatcher\EventDispatcher());
        $locks = new LockFactory(new FlockStore($this->paths->locksDir()));

        return new InstanceWatcher(
            $this->instances,
            $this->ddev,
            new JobReaper($jobs, $this->instances, $service, $locks, $this->clock),
            new JobPruner($jobs, $this->paths, $this->clock, new \App\Domain\ApiCall\ApiCallRepository($this->em)),
            $feed,
            90,
            $withAgent ? $this->agent : null,
            $logger ?? new \Psr\Log\NullLogger(),
            null,
            null,
            null,
            $withIsolation ? new \App\Fleet\Instances\InstanceDbIsolationWriter($this->ddev) : null,
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

    public function testTheWatcherRedisconnectsADbThatWasAttachedAgain(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $id = $instance->id();
        $this->runner->expect(['ddev', 'list'], $this->ddevList([$id => 'running']));
        $this->runner->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-db|db|com.ddev.site-name='.$id."\n"));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(0));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"x_default":{}}'));

        $this->watcher(false, true)->tick($this->clock->now());

        self::assertCount(1, $this->runner->callsMatching(['docker', 'network', 'disconnect', 'ddev_default', 'ddev-'.$id.'-db']));
    }

    public function testTheIsolationPassIsThrottledAndAFailureIsLoggedOncePerChange(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $id = $instance->id();
        $logger = new class extends \Psr\Log\AbstractLogger {
            public int $errors = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('error' === $level) {
                    ++$this->errors;
                }
            }
        };
        $this->runner->expect(['ddev', 'list'], $this->ddevList([$id => 'running']));
        $this->runner->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-db|db|com.ddev.site-name='.$id."\n"));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(1, '', 'denied'));
        $watcher = $this->watcher(false, true, $logger);
        $now = $this->clock->now();

        $watcher->tick($now); // converge fails: only logged, tick does not throw
        self::assertSame(1, $logger->errors);
        $psCalls = \count($this->runner->callsMatching(['docker', 'ps']));

        $watcher->tick($now->modify('+30 seconds')); // inside the 120 s throttle: no new pass
        self::assertCount($psCalls, $this->runner->callsMatching(['docker', 'ps']));

        $this->runner->expect(['ddev', 'list'], $this->ddevList([$id => 'running']));
        $this->runner->expect(['docker', 'ps'], new CommandResult(0, 'ddev-'.$id.'-db|db|com.ddev.site-name='.$id."\n"));
        $this->runner->expect(['docker', 'inspect'], new CommandResult(0, '{"ddev_default":{}}'));
        $this->runner->expect(['docker', 'network', 'disconnect'], new CommandResult(1, '', 'denied'));
        $watcher->tick($now->modify('+121 seconds')); // same failure again: no second log
        self::assertGreaterThan($psCalls, \count($this->runner->callsMatching(['docker', 'ps'])));
        self::assertSame(1, $logger->errors);
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

    public function testTheWatcherRefreshesTheAgentOfRunningOptedInInstancesOnly(): void
    {
        mkdir($this->paths->sshDir(), 0o700, true);
        touch($this->paths->sshPrivateKey(\App\Domain\Ssh\SshKeyRole::Push));
        $this->project->changeContainerSsh(true);
        $running = $this->instance('a', 'develop', InstanceState::Running);
        $this->instance('b', 'develop', InstanceState::Stopped);
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running', 'acme--b' => 'stopped']));
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AGENT_PID=11; export SSH_AGENT_PID;\n"));

        $watcher = $this->watcher(true);
        $watcher->tick($this->clock->now());

        $adds = $this->runner->callsMatching(['ssh-add', '-t']);
        self::assertCount(1, $adds, 'one key refresh, for the running instance');
        self::assertSame($this->paths->agentSocket($running->id()), $adds[0]->env['SSH_AUTH_SOCK']);

        $this->runner->reset();
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running', 'acme--b' => 'stopped']));
        $watcher->tick($this->clock->now()->modify('+30 seconds'));
        self::assertSame([], $this->runner->callsMatching(['ssh-add', '-t']), 'not due again so soon');

        $this->runner->reset();
        $this->runner->expect(['ddev', 'list'], $this->ddevList(['acme--a' => 'running', 'acme--b' => 'stopped']));
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AGENT_PID=12; export SSH_AGENT_PID;\n"));
        $watcher->tick($this->clock->now()->modify('+'.(\App\Fleet\Ssh\InstanceSshAgent::REFRESH_SECONDS + 1).' seconds'));
        self::assertCount(1, $this->runner->callsMatching(['ssh-add', '-t']), 'refreshed again when due');
    }

    public function testTheWatcherKeepsRekeyingTheAgentOfAnUnknownInstanceThatHasOne(): void
    {
        mkdir($this->paths->sshDir(), 0o700, true);
        touch($this->paths->sshPrivateKey(\App\Domain\Ssh\SshKeyRole::Push));
        $this->project->changeContainerSsh(true);
        $withAgent = $this->instance('a', 'develop', InstanceState::Unknown);
        $this->instance('b', 'develop', InstanceState::Unknown);
        mkdir($this->paths->agentSocketDir($withAgent->id()), 0o700, true);
        touch($this->paths->agentSocket($withAgent->id()));
        // ddev list omits both: they stay "unknown". Only the one whose agent answers (ssh-add -l exit 1: no keys) is re-keyed.
        $this->runner->expect(['ddev', 'list'], $this->ddevList([]));
        $this->runner->expect(['ssh-add', '-l'], new CommandResult(1, ''));

        $this->watcher(true)->tick($this->clock->now());

        $adds = $this->runner->callsMatching(['ssh-add', '-t']);
        self::assertCount(1, $adds, 'the key of the unknown instance with an agent is renewed');
        self::assertSame($this->paths->agentSocket($withAgent->id()), $adds[0]->env['SSH_AUTH_SOCK']);
        self::assertSame([], $this->runner->callsMatching(['ssh-agent']), 'no agent is started for an unknown instance');
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
        $instanceUpdates = fn (): int => \count(array_filter($this->published, static fn (string $p): bool => str_starts_with($p, '/instances/acme ')));
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
