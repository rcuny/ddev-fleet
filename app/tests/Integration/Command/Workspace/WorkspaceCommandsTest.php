<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Workspace;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Ssh\SshKeyRole;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Fleet\Workspace\ClaudeTokenRotation;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\RunsFleetCommands;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkspaceCommandsTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;
    use RunsFleetCommands;

    private const string TOKEN = 'sk-ant-oat01-rotation-unique-0123456789';
    private const string NEXT = 'sk-ant-oat01-second-token-unique-9876543210';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        [$exit, $text] = $this->console('fleet:host:init', ['--domain' => 'fleet.example.com']);
        self::assertSame(0, $exit, $text);
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get(CommandRunner::class);
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);
        $runner->reset();

        return $runner;
    }

    private function store(): SecretStore
    {
        $this->em->clear();

        return self::getContainer()->get(SecretStore::class);
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function audit(): array
    {
        $this->em->clear();

        return array_map(
            static fn ($e): array => [$e->actor(), $e->action(), $e->subject()],
            new AuditEntryRepository($this->em)->latest(50),
        );
    }

    // --- fleet:claude:token:set

    public function testRotatingTheTokenTwiceStoresTheNewOneAndBumpsTheTimestamp(): void
    {
        $first = $this->cli('fleet:claude:token:set', [], [self::TOKEN."\n"]);
        self::assertSame(0, $first->getStatusCode(), $first->getErrorOutput());
        self::assertSame(self::TOKEN, $this->store()->getFleetWide('claude_oauth_token'));
        $firstAt = $this->store()->fleetWideUpdatedAt('claude_oauth_token');
        self::assertNotNull($firstAt);

        sleep(1);
        $second = $this->cli('fleet:claude:token:set', [], [self::NEXT."\n"]);
        self::assertSame(0, $second->getStatusCode(), $second->getErrorOutput());
        self::assertSame(self::NEXT, $this->store()->getFleetWide('claude_oauth_token'));
        self::assertGreaterThan($firstAt, $this->store()->fleetWideUpdatedAt('claude_oauth_token'));

        foreach ([$first, $second] as $run) {
            $seen = $run->getDisplay().$run->getErrorOutput();
            self::assertStringNotContainsString(self::TOKEN, $seen);
            self::assertStringNotContainsString(self::NEXT, $seen);
            self::assertStringContainsString('Claude token stored', $seen);
        }
        self::assertSame(
            [['cli', AuditLog::CLAUDE_TOKEN_ROTATE, 'fleet/claude_oauth_token'], ['cli', AuditLog::CLAUDE_TOKEN_ROTATE, 'fleet/claude_oauth_token']],
            $this->audit(),
        );
        self::assertStringNotContainsString(self::TOKEN, json_encode($this->audit(), \JSON_THROW_ON_ERROR));
    }

    public function testTheTokenCanBeTypedAtAHiddenPrompt(): void
    {
        $run = $this->cli('fleet:claude:token:set', [], [self::TOKEN], true);

        self::assertSame(0, $run->getStatusCode(), $run->getErrorOutput());
        self::assertSame(self::TOKEN, $this->store()->getFleetWide('claude_oauth_token'));
        self::assertStringNotContainsString(self::TOKEN, $run->getDisplay().$run->getErrorOutput());
    }

    public function testABadTokenIsRefusedWithoutEchoAndKeepsTheOldOne(): void
    {
        $this->cli('fleet:claude:token:set', [], [self::TOKEN."\n"]);

        foreach (["has a space-1234567890\n", '', str_repeat('x', 513)."\n"] as $bad) {
            $run = $this->cli('fleet:claude:token:set', [], [$bad]);
            self::assertSame(2, $run->getStatusCode());
            self::assertStringNotContainsString('space-1234567890', $run->getDisplay().$run->getErrorOutput());
        }
        self::assertSame(self::TOKEN, $this->store()->getFleetWide('claude_oauth_token'));
        self::assertCount(1, $this->audit(), 'only the good rotation was recorded');
    }

    public function testTheRunningInstancesAreListed(): void
    {
        $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running, InstanceState::Stopped, InstanceState::Running]);

        $run = $this->cli('fleet:claude:token:set', [], [self::TOKEN."\n"]);

        self::assertStringContainsString('acme--i1', $run->getDisplay());
        self::assertStringContainsString('acme--i3', $run->getDisplay());
        self::assertStringNotContainsString('acme--i2', $run->getDisplay());
    }

    public function testNoLogLineCarriesTheToken(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level.' '.$message.' '.json_encode($context);
            }
        };
        $container = self::getContainer();
        $rotation = new ClaudeTokenRotation(
            $container->get(SecretStore::class),
            $container->get(AuditLog::class),
            $container->get(InstanceRepository::class),
            $container->get(ClockInterface::class),
            $logger,
        );

        $result = $rotation->rotate('  '.self::TOKEN.' ', 'admin');

        self::assertNotSame([], $logger->lines);
        self::assertStringNotContainsString('rotation-unique', implode("\n", $logger->lines).json_encode($result));
        self::assertSame(self::TOKEN, $this->store()->getFleetWide('claude_oauth_token'), 'surrounding blanks are trimmed');
        self::assertNotNull($rotation->lastRotated());
    }

    // --- fleet:workspace:check

    public function testTheCheckReportsMissingThenPresentWithoutValues(): void
    {
        $missing = $this->cli('fleet:workspace:check', ['--json' => true]);
        self::assertSame(1, $missing->getStatusCode());
        self::assertSame(['push_key' => false, 'git_identity' => false, 'claude_token' => false], $this->json($missing));

        $paths = self::getContainer()->get(FleetPaths::class);
        @mkdir($paths->sshDir(), 0o700, true);
        file_put_contents($paths->sshPrivateKey(SshKeyRole::Push), 'PRIVATE-KEY-BODY');
        file_put_contents($paths->sshPublicKey(SshKeyRole::Push), 'ssh-ed25519 AAAA push');
        $repository = new HostSettingsRepository($this->em);
        $host = $repository->get();
        self::assertNotNull($host);
        $host->changeGitIdentity('Fleet Bot', 'bot@fleet.example.com');
        $repository->save($host);
        $this->store()->putFleetWide('claude_oauth_token', self::TOKEN, 'admin');

        $present = $this->cli('fleet:workspace:check');
        self::assertSame(0, $present->getStatusCode(), $present->getDisplay());
        self::assertSame(3, substr_count($present->getDisplay(), 'present'));
        foreach (['PRIVATE-KEY-BODY', self::TOKEN, 'bot@fleet', 'AAAA'] as $value) {
            self::assertStringNotContainsString($value, $present->getDisplay().$present->getErrorOutput());
        }
    }

    // --- fleet:tmux:*

    public function testEnsureCreatesTheSessionAndAWindowPerInstanceWithACheckout(): void
    {
        $runner = $this->runner();
        $runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(1, '', 'no server'));
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running, InstanceState::Stopped, InstanceState::Destroying]);
        $paths = self::getContainer()->get(FleetPaths::class);
        mkdir($paths->instanceDir($instances[0]->id()), 0o750, true);
        mkdir($paths->instanceDir($instances[2]->id()), 0o750, true);

        $run = $this->cli('fleet:tmux:ensure');

        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        self::assertCount(1, $runner->callsMatching(['tmux', '-L', 'fleet', 'new-session']));
        $windows = $runner->callsMatching(['tmux', '-L', 'fleet', 'new-window']);
        self::assertCount(1, $windows, 'the stopped one has no checkout, the destroying one is going away');
        self::assertContains('acme--i1', $windows[0]->argv);
    }

    public function testListPrintsTheWindowsAsJson(): void
    {
        $this->runner()->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\nacme--i1\n"));

        $run = $this->cli('fleet:tmux:list', ['--json' => true]);

        self::assertSame(['session' => 'fleet', 'running' => true, 'windows' => ['host', 'acme--i1']], $this->json($run));
    }

    public function testResetOfOneWindowAndOfAnUnknownInstance(): void
    {
        $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running]);
        $runner = $this->runner();
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "host\nacme--i1\n"));

        $run = $this->cli('fleet:tmux:reset', ['window' => 'acme--i1']);
        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        self::assertCount(1, $runner->callsMatching(['tmux', '-L', 'fleet', 'kill-window']));

        $unknown = $this->cli('fleet:tmux:reset', ['window' => 'nope--x']);
        self::assertSame(1, $unknown->getStatusCode());
    }

    // --- fleet:tmux:sidebar

    public function testTheSidebarShowsStateUrlAndLiveBranches(): void
    {
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running]);
        $this->runner()->expect(['git', 'for-each-ref'], new CommandResult(0, "*develop\n feature/FLE-1\n"));

        $run = $this->cli('fleet:tmux:sidebar', ['--window' => $instances[0]->id(), '--once' => true]);

        self::assertSame(0, $run->getStatusCode(), $run->getErrorOutput());
        self::assertSame(
            "acme--i1\nstate    running\nurl      https://acme--i1.fleet.example.com\nproject  acme\nbranch   develop\n\nbranches\n* develop\n  feature/FLE-1\n",
            $run->getDisplay(),
        );
    }

    public function testTheSidebarOfAGoneInstanceAndABadWindow(): void
    {
        $gone = $this->cli('fleet:tmux:sidebar', ['--window' => 'acme--gone', '--once' => true]);
        self::assertSame(0, $gone->getStatusCode());
        self::assertStringContainsString('no longer exists', $gone->getDisplay());

        self::assertSame(2, $this->cli('fleet:tmux:sidebar', ['--window' => '--bad', '--once' => true])->getStatusCode());
        self::assertSame(2, $this->cli('fleet:tmux:sidebar', ['--once' => true])->getStatusCode());
    }
}
