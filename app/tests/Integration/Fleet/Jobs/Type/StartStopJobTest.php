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
        new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent, $this->preStartGate())->execute($job, $this->logFor($job));
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

    public function testStartRestartsTheAgentOfAnOptedInProjectAndOnlyWarnsWhenItCannot(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        mkdir($this->paths->sshDir(), 0o700, true);
        touch($this->paths->sshPrivateKey(\App\Domain\Ssh\SshKeyRole::Push));
        $this->project->changeContainerSsh(true);
        $this->runner->expect(['ssh-agent'], new CommandResult(1, '', 'cannot bind'));

        $job = $this->job('start', $instance);
        new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent, $this->preStartGate())->execute($job, $this->logFor($job));

        self::assertSame(InstanceState::Running, $instance->state());
        self::assertStringContainsString('warning: ssh-agent did not start', $this->logText($job));
        self::assertDirectoryExists($this->paths->agentSocketDir($instance->id()), 'present before ddev start, so Docker never creates it');
    }

    /**
     * @return list<string> the key files given to `ssh-add -t`
     */
    private function loadedKeys(): array
    {
        $keys = [];
        foreach ($this->runner->callsMatching(['ssh-add', '-t']) as $call) {
            $argv = $call->argv;
            $keys[] = (string) end($argv);
        }

        return $keys;
    }

    public function testEveryStartLoadsTheTemplatesExtraKeysAndNoOtherTemplateGetsThem(): void
    {
        $this->project->changeContainerSsh(true);
        mkdir($this->paths->sshDir(), 0o700, true);
        foreach (['push', 'staging'] as $name) {
            touch($this->paths->sshDir().'/'.$name.'_ed25519');
            chmod($this->paths->sshDir().'/'.$name.'_ed25519', 0o600);
        }
        $this->template->changeSshKeys(['staging']);
        $this->templates->save($this->template);
        $plain = $this->saveTemplate('plain', []);

        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        $other = $this->instance('other', 'develop', InstanceState::Stopped, $plain->name());
        $this->checkout($other);
        $socket = $this->paths->agentSocket($instance->id());
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AUTH_SOCK=$socket; export SSH_AUTH_SOCK;\nSSH_AGENT_PID=4242; export SSH_AGENT_PID;\n"));

        // start job, stop, start again: the agent is gone in between, the key is loaded again.
        $this->start($instance);
        $this->agent->stop($instance->id());
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AUTH_SOCK=$socket; export SSH_AUTH_SOCK;\nSSH_AGENT_PID=4343; export SSH_AGENT_PID;\n"));
        $this->agent->startFor($instance); // what the second StartJob and the watcher's refresh call

        $staging = $this->paths->sshDir().'/staging_ed25519';
        $push = $this->paths->sshPrivateKey(\App\Domain\Ssh\SshKeyRole::Push);
        self::assertSame([$push, $staging, $push, $staging], $this->loadedKeys());

        // The recording runner keeps its history, so the other instance is checked by what is added after the first one.
        $before = \count($this->loadedKeys());
        $socketOther = $this->paths->agentSocket($other->id());
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AUTH_SOCK=$socketOther; export SSH_AUTH_SOCK;\nSSH_AGENT_PID=4444; export SSH_AGENT_PID;\n"));
        $this->start($other);
        self::assertSame([$push], \array_slice($this->loadedKeys(), $before), 'an instance of another template gets the push key only');
    }

    public function testStartRemovesTheOverrideAndAgentOfAProjectThatOptedOut(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        $override = $this->paths->instanceDir($instance->id()).'/'.\App\Fleet\Ssh\InstanceSshAgent::OVERRIDE_FILE;
        @mkdir(\dirname($override), 0o755, true);
        file_put_contents($override, 'old');
        mkdir($this->paths->agentSocketDir($instance->id()), 0o700, true);
        touch($this->paths->agentSocket($instance->id()));
        file_put_contents($this->paths->agentPidFile($instance->id()), "99\n");

        $job = $this->job('start', $instance);
        new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent, $this->preStartGate())->execute($job, $this->logFor($job));

        self::assertFileDoesNotExist($override);
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir($instance->id()));
        self::assertCount(1, $this->runner->callsMatching(['ssh-agent', '-k']));
        self::assertStringContainsString('no longer uses container_ssh', $this->logText($job));
    }

    public function testStartLeavesAnOptOutProjectAlone(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $this->checkout($instance);
        mkdir($this->paths->sshDir(), 0o700, true);
        touch($this->paths->sshPrivateKey(\App\Domain\Ssh\SshKeyRole::Push));

        $this->start($instance);

        self::assertSame(['ddev start'], $this->commandNames());
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir($instance->id()));
    }

    public function testStopStopsTheInstancesAgent(): void
    {
        $instance = $this->instance('main', 'develop', InstanceState::Running);
        $this->checkout($instance);
        mkdir($this->paths->agentSocketDir($instance->id()), 0o700, true);
        touch($this->paths->agentSocket($instance->id()));
        file_put_contents($this->paths->agentPidFile($instance->id()), "99\n");

        $job = $this->job('stop', $instance);
        new StopJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent)->execute($job, $this->logFor($job));

        self::assertCount(1, $this->runner->callsMatching(['ssh-agent', '-k']));
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir($instance->id()));
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
        new StartJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent, $this->preStartGate())->execute($job, $this->logFor($job));

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

        new StopJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent)->execute($job, $this->logFor($job));

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
            new StopJob($this->instances, $this->ddev, $this->paths, $this->feed, $this->clock, $this->agent)->execute($job, $this->logFor($job));
            self::fail('expected a failure');
        } catch (JobFailed $e) {
            self::assertSame('ddev stop failed with exit code 1', $e->getMessage());
        }
        self::assertSame(InstanceState::Running, $instance->state());
    }

    public function testStartIsRefusedWhenTheCheckoutHoldsADockerSocketOverride(): void
    {
        // FLE-298: a project that added the override after its deploy must not start.
        $instance = $this->instance('main', 'develop', InstanceState::Stopped);
        $dir = $this->checkout($instance);
        mkdir($dir.'/.ddev', 0o750, true);
        file_put_contents($dir.'/.ddev/docker-compose.dockersock.yaml', "services:\n  web:\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n");

        try {
            $this->start($instance);
            self::fail('expected the start to be refused');
        } catch (JobFailed $e) {
            self::assertStringContainsString('docker-compose.dockersock.yaml: services.web.volumes[0]', $e->getMessage());
        }
        self::assertNotContains('ddev start', $this->commandNames());
        self::assertSame(InstanceState::Stopped, $instance->state());
    }
}
