<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Ssh;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Ssh\ExtraSshKey;
use App\Fleet\Ssh\InstanceSshAgent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class InstanceSshAgentTest extends TestCase
{
    private const string ID = 'acme--main';

    private string $home;
    private FleetPaths $paths;
    private RecordingCommandRunner $runner;
    private InstanceSshAgent $agent;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/fle145-agent-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/ssh', 0o700, true);
        $this->paths = new FleetPaths($this->home, $this->home.'/caddy');
        $this->runner = new RecordingCommandRunner();
        $this->agent = new InstanceSshAgent($this->runner, $this->paths, new ExtraSshKey($this->paths), $this->templates());
    }

    private ?Template $stored = null;

    private function templates(): TemplateRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(fn (): ?Template => $this->stored);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new TemplateRepository($em);
    }

    private function extraKey(string $name, int $mode = 0o600): string
    {
        $file = $this->home.'/ssh/'.$name.'_ed25519';
        touch($file);
        chmod($file, $mode);

        return $file;
    }

    /**
     * @return list<string> the key files given to `ssh-add -t`
     */
    private function loadedKeys(): array
    {
        $keys = [];
        foreach ($this->runner->calls() as $call) {
            if ('ssh-add' === $call->argv[0] && '-t' === ($call->argv[1] ?? '')) {
                $keys[] = $call->argv[array_key_last($call->argv)];
            }
        }

        return $keys;
    }

    private function agentIsUp(): void
    {
        $this->pushKey();
        mkdir($this->paths->agentSocketDir(self::ID), 0o700, true);
        touch($this->paths->agentSocket(self::ID));
        $this->runner->expect(['ssh-add', '-l'], new CommandResult(1, 'The agent has no identities.'));
    }

    public function testStartLoadsTheTemplatesExtraKeysAfterThePushKeyWithTheSameLifetime(): void
    {
        $this->agentIsUp();
        $staging = $this->extraKey('staging');

        $this->agent->start(self::ID, ['staging']);

        $adds = array_values(array_filter(array_map(static fn ($call): array => $call->argv, $this->runner->calls()), static fn (array $argv): bool => 'ssh-add' === $argv[0] && '-t' === $argv[1]));
        self::assertSame([['ssh-add', '-t', '86400', $this->home.'/ssh/push_ed25519'], ['ssh-add', '-t', '86400', $staging]], $adds);
    }

    public function testStartWithoutPushLoadsOnlyTheExtraKeysAndDropsAnEarlierPushKey(): void
    {
        $this->agentIsUp();
        $staging = $this->extraKey('staging');

        $this->agent->start(self::ID, ['staging'], false);

        self::assertSame([$staging], $this->loadedKeys(), 'the push key is never loaded');
        $removed = array_values(array_filter(array_map(static fn ($call): array => $call->argv, $this->runner->calls()), static fn (array $argv): bool => 'ssh-add' === $argv[0] && '-d' === ($argv[1] ?? '')));
        self::assertSame([['ssh-add', '-d', $this->home.'/ssh/push_ed25519.pub']], $removed);
    }

    public function testExtrasOnlyFailsWhenThePushKeyCannotBeRemovedFromTheAgent(): void
    {
        $this->agentIsUp();
        $this->extraKey('staging');
        file_put_contents($this->home.'/ssh/push_ed25519.pub', "ssh-ed25519 AAAApushblob fleet-push@x\n");
        $this->runner->expect(['ssh-add', '-d'], new CommandResult(1, '', 'Could not remove identity'));
        $this->runner->expect(['ssh-add', '-L'], new CommandResult(0, "ssh-ed25519 AAAApushblob comment\n"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('push key could not be removed');
        $this->agent->start(self::ID, ['staging'], false);
    }

    public function testExtrasOnlyAcceptsAFailedRemovalWhenThePushKeyWasNeverLoaded(): void
    {
        $this->agentIsUp();
        $this->extraKey('staging');
        file_put_contents($this->home.'/ssh/push_ed25519.pub', "ssh-ed25519 AAAApushblob fleet-push@x\n");
        $this->runner->expect(['ssh-add', '-d'], new CommandResult(1, '', 'Could not remove identity'));
        $this->runner->expect(['ssh-add', '-L'], new CommandResult(1, 'The agent has no identities.'));

        $this->agent->start(self::ID, ['staging'], false);

        $this->addToAssertionCount(1);
    }

    public function testStartFailsClearlyForAnExtraKeyThatIsNotUsable(): void
    {
        $this->agentIsUp();
        $this->extraKey('loose', 0o644);

        foreach (['missing', 'loose', 'deploy', 'push', '../x'] as $name) {
            try {
                $this->agent->start(self::ID, [$name]);
                self::fail("$name was loaded");
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString($this->home, $e->getMessage(), 'no path in the message');
            }
        }
        $argvs = array_map(static fn ($call): array => $call->argv, $this->runner->calls());
        self::assertSame([], array_filter($argvs, static fn (array $argv): bool => 'ssh-add' === $argv[0] && '-t' === ($argv[1] ?? '')), 'nothing was loaded, not even the push key');
    }

    public function testStartForReadsTheKeysOfTheInstancesOwnTemplate(): void
    {
        $this->agentIsUp();
        $staging = $this->extraKey('staging');
        $project = new Project('acme', 'https://git.example.com/acme/acme.git');
        $instance = new Instance($project, 'main', 'agent', 'develop', 'test');

        $this->stored = new Template($project, 'agent');
        $this->stored->changeSshKeys(['staging']);
        $this->agent->startFor($instance);
        $loaded = $this->loadedKeys();
        self::assertContains($staging, $loaded);

        // Another template of the same project (or a template that is gone) gets the push key only.
        $this->runner = new RecordingCommandRunner();
        $this->agent = new InstanceSshAgent($this->runner, $this->paths, new ExtraSshKey($this->paths), $this->templates());
        $this->runner->expect(['ssh-add', '-l'], new CommandResult(1, 'none'));
        $this->stored = new Template($project, 'agent');
        $this->agent->startFor($instance);
        $loaded = $this->loadedKeys();
        self::assertSame([$this->home.'/ssh/push_ed25519'], $loaded);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    private function pushKey(): void
    {
        touch($this->home.'/ssh/push_ed25519');
        touch($this->home.'/ssh/deploy_ed25519');
    }

    public function testStartRunsADedicatedAgentAndLoadsOnlyThePushKeyWithALifetime(): void
    {
        $this->pushKey();
        $socket = $this->paths->agentSocket(self::ID);
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AUTH_SOCK=$socket; export SSH_AUTH_SOCK;\nSSH_AGENT_PID=4242; export SSH_AGENT_PID;\n"));

        $this->agent->start(self::ID);

        $calls = $this->runner->calls();
        self::assertSame(['ssh-agent', '-a', $socket], $calls[0]->argv);
        self::assertSame(['ssh-add', '-t', '86400', $this->home.'/ssh/push_ed25519'], $calls[1]->argv);
        self::assertSame(['SSH_AUTH_SOCK' => $socket], $calls[1]->env);
        self::assertCount(2, $calls, 'the deploy key is never loaded, and DDEV\'s own agent is never touched');
        self::assertSame(0o700, fileperms($this->paths->agentSocketDir(self::ID)) & 0o777);
        self::assertSame(0o700, fileperms($this->paths->runDir()) & 0o777);
        self::assertSame("4242\n", file_get_contents($this->paths->agentPidFile(self::ID)));
        self::assertSame(0o600, fileperms($this->paths->agentPidFile(self::ID)) & 0o777);
    }

    public function testStartIsIdempotentAndReusesALiveAgent(): void
    {
        $this->pushKey();
        mkdir($this->paths->agentSocketDir(self::ID), 0o700, true);
        touch($this->paths->agentSocket(self::ID));
        $this->runner->expect(['ssh-add', '-l'], new CommandResult(1, 'The agent has no identities.'));

        $this->agent->start(self::ID);

        $argvs = array_map(static fn ($call): array => $call->argv, $this->runner->calls());
        self::assertSame([], array_filter($argvs, static fn (array $argv): bool => 'ssh-agent' === $argv[0]), 'no second agent');
        self::assertSame('-t', $argvs[1][1], 'the key lifetime is refreshed');
    }

    public function testADeadSocketIsReplaced(): void
    {
        $this->pushKey();
        mkdir($this->paths->agentSocketDir(self::ID), 0o700, true);
        touch($this->paths->agentSocket(self::ID));
        $this->runner->expect(['ssh-add', '-l'], new CommandResult(2, 'Could not open a connection to your authentication agent.'));
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AGENT_PID=77; export SSH_AGENT_PID;\n"));

        $this->agent->start(self::ID);

        self::assertSame("77\n", file_get_contents($this->paths->agentPidFile(self::ID)));
    }

    public function testAFailingAgentOrKeyLoadThrowsButTheDirectoryExists(): void
    {
        $this->pushKey();
        $this->runner->expect(['ssh-agent'], new CommandResult(1, '', 'no'));
        try {
            $this->agent->start(self::ID);
            self::fail('expected a failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ssh-agent did not start', $e->getMessage());
        }
        self::assertDirectoryExists($this->paths->agentSocketDir(self::ID), 'Docker must never create it as root');

        $this->runner->reset();
        $this->runner->expect(['ssh-agent'], new CommandResult(0, "SSH_AGENT_PID=5; export SSH_AGENT_PID;\n"));
        $this->runner->expect(['ssh-add', '-t'], new CommandResult(1, '', 'bad passphrase'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('push key could not be loaded');
        $this->agent->start(self::ID);
    }

    public function testStopKillsTheAgentAndRemovesTheSocketDirectoryAndPid(): void
    {
        mkdir($this->paths->agentSocketDir(self::ID), 0o700, true);
        touch($this->paths->agentSocket(self::ID));
        file_put_contents($this->paths->agentPidFile(self::ID), "4242\n");

        $this->agent->stop(self::ID);

        $kill = $this->runner->callsMatching(['ssh-agent', '-k'])[0];
        self::assertSame('4242', $kill->env['SSH_AGENT_PID']);
        self::assertSame($this->paths->agentSocket(self::ID), $kill->env['SSH_AUTH_SOCK']);
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir(self::ID));
        self::assertFileDoesNotExist($this->paths->agentPidFile(self::ID));
    }

    public function testStopNeverSignalsAPidWhoseSocketIsDead(): void
    {
        mkdir($this->paths->agentSocketDir(self::ID), 0o700, true);
        touch($this->paths->agentSocket(self::ID));
        file_put_contents($this->paths->agentPidFile(self::ID), "4242\n");
        $this->runner->expect(['ssh-add', '-l'], new CommandResult(2));

        $this->agent->stop(self::ID);

        self::assertSame([], $this->runner->callsMatching(['ssh-agent', '-k']), 'the pid may belong to another process by now');
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir(self::ID));
    }

    public function testStopWithoutAnAgentDoesNothing(): void
    {
        $this->agent->stop(self::ID);

        self::assertSame([], $this->runner->calls());
    }

    public function testTheComposeOverrideMountsOnlyThisInstancesSocketDirectory(): void
    {
        $text = $this->agent->composeOverride(self::ID);
        $yaml = Yaml::parse($text);

        self::assertIsArray($yaml);
        self::assertSame(['services'], array_keys($yaml));
        self::assertSame(['web'], array_keys($yaml['services']));
        self::assertSame([$this->paths->agentSocketDir(self::ID).':/run/fleet-ssh-agent'], $yaml['services']['web']['volumes']);
        self::assertSame(['SSH_AUTH_SOCK=/run/fleet-ssh-agent/agent.sock'], $yaml['services']['web']['environment']);
        self::assertStringNotContainsString('push_ed25519', $text, 'a path to a key never reaches the container');
        self::assertStringNotContainsString($this->home.'/ssh', $text);
    }

    public function testHasKeyIsTheExistenceOfThePushKey(): void
    {
        self::assertFalse($this->agent->hasKey());
        touch($this->home.'/ssh/push_ed25519');
        self::assertTrue($this->agent->hasKey());
    }

    public function testCleanUpStopsTheAgentAndRemovesTheOverrideOfAnOptedOutInstance(): void
    {
        $checkout = $this->home.'/checkout';
        mkdir($checkout.'/.ddev', 0o755, true);
        file_put_contents($checkout.'/'.InstanceSshAgent::OVERRIDE_FILE, 'x');
        mkdir($this->paths->agentSocketDir(self::ID), 0o700, true);
        touch($this->paths->agentSocket(self::ID));
        file_put_contents($this->paths->agentPidFile(self::ID), "4242\n");

        self::assertTrue($this->agent->cleanUp(self::ID, $checkout));

        self::assertFileDoesNotExist($checkout.'/'.InstanceSshAgent::OVERRIDE_FILE);
        self::assertDirectoryDoesNotExist($this->paths->agentSocketDir(self::ID));
        self::assertCount(1, $this->runner->callsMatching(['ssh-agent', '-k']));
    }

    public function testCleanUpWithNothingToCleanRunsNothing(): void
    {
        self::assertFalse($this->agent->cleanUp(self::ID, $this->home.'/checkout'));
        self::assertSame([], $this->runner->calls());
    }
}
