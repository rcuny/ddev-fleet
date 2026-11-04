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
        self::assertSame(['push_key' => false, 'git_identity' => false, 'claude_token' => false, 'tmux_server' => true], $this->json($missing));

        $paths = self::getContainer()->get(FleetPaths::class);
        @mkdir($paths->sshDir(), 0o700, true);
        file_put_contents($paths->sshPrivateKey(SshKeyRole::Push), 'PRIVATE-KEY-BODY');
        file_put_contents($paths->sshPublicKey(SshKeyRole::Push), 'ssh-ed25519 AAAA push');
        $repository = new HostSettingsRepository($this->em);
        $host = $repository->get();
        self::assertNotNull($host);
        $host->changeGitIdentity('Fleet Bot', 'bot@fleet.example.com', new \DateTimeImmutable());
        $repository->save($host);
        $this->store()->putFleetWide('claude_oauth_token', self::TOKEN, 'admin');

        $present = $this->cli('fleet:workspace:check');
        self::assertSame(0, $present->getStatusCode(), $present->getDisplay());
        self::assertSame(3, substr_count($present->getDisplay(), 'present'));
        foreach (['PRIVATE-KEY-BODY', self::TOKEN, 'bot@fleet', 'AAAA'] as $value) {
            self::assertStringNotContainsString($value, $present->getDisplay().$present->getErrorOutput());
        }
    }

    public function testTheCheckReportsADeadTmuxServer(): void
    {
        $this->store()->putFleetWide('claude_oauth_token', self::TOKEN, 'admin');
        $this->runner()->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(1, '', 'no server running'));

        $run = $this->cli('fleet:workspace:check', ['--json' => true]);

        self::assertSame(1, $run->getStatusCode());
        self::assertFalse($this->json($run)['tmux_server']);
        self::assertTrue($this->json($run)['claude_token']);
    }

    // --- fleet:tmux:*

    private function wholeWindow(string $sidebar = '%7'): string
    {
        return implode("\n", [
            implode("\t", [$sidebar, 0, 0, 50, 200, 'sidebar', 'sh']),
            implode("\t", ['%5', 51, 0, 74, 200, '', 'bash']),
            implode("\t", ['%6', 126, 0, 74, 200, '', 'bash']),
        ])."\n";
    }

    public function testEnsureCreatesTheSessionGeneralAndAWindowPerInstanceWithACheckout(): void
    {
        $runner = $this->runner();
        $runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(1, '', 'no server'));
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running, InstanceState::Stopped, InstanceState::Destroying]);
        $paths = self::getContainer()->get(FleetPaths::class);
        mkdir($paths->instanceDir($instances[0]->id()), 0o750, true);
        mkdir($paths->instanceDir($instances[2]->id()), 0o750, true);
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));

        $run = $this->cli('fleet:tmux:ensure');

        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        self::assertCount(1, $runner->callsMatching(['tmux', '-L', 'fleet', 'new-session']));
        self::assertNotSame([], $runner->callsMatching(['tmux', '-L', 'fleet', 'bind-key', 'R']), 'settings are applied');
        $windows = $runner->callsMatching(['tmux', '-L', 'fleet', 'new-window']);
        self::assertCount(1, $windows, 'general came with the session; the stopped one has no checkout, the destroying one is going away');
        self::assertContains('acme--i1', $windows[0]->argv);
    }

    public function testEnsurePrunesManagedWindowsOfGoneInstancesAndNeverTheOthers(): void
    {
        $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running]);
        $runner = $this->runner();
        $runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(0));
        // ensureGeneral: general is there and whole
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\nacme--i1\nacme--gone\nscratch\n"));
        $runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $this->wholeWindow()));
        // the pruning pass: three managed windows, one of them unknown to the database, one untagged
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\t1\nacme--gone\t1\nscratch\t\n"));
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\nacme--gone\n"));

        $run = $this->cli('fleet:tmux:ensure');

        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        $kills = $runner->callsMatching(['tmux', '-L', 'fleet', 'kill-window']);
        self::assertCount(1, $kills);
        self::assertSame('=fleet:=acme--gone', $kills[0]->argv[5]);
    }

    public function testEnsureSkipsAnInstanceWhoseLockIsHeld(): void
    {
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running]);
        $paths = self::getContainer()->get(FleetPaths::class);
        mkdir($paths->instanceDir($instances[0]->id()), 0o750, true);
        $lock = self::getContainer()->get(\Symfony\Component\Lock\LockFactory::class)->createLock('instance.'.$instances[0]->id());
        self::assertTrue($lock->acquire());
        $runner = $this->runner();
        $runner->expect(['tmux', '-L', 'fleet', 'has-session'], new CommandResult(0));

        try {
            $run = $this->cli('fleet:tmux:ensure');
        } finally {
            $lock->release();
        }

        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        self::assertSame([], array_filter($runner->callsMatching(['tmux', '-L', 'fleet', 'new-window']), static fn ($c): bool => \in_array('acme--i1', $c->argv, true)), 'the busy instance was left alone');
    }

    public function testListPrintsTheWindowsAsJson(): void
    {
        $this->runner()->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\nacme--i1\n"));

        $run = $this->cli('fleet:tmux:list', ['--json' => true]);

        self::assertSame(['session' => 'fleet', 'running' => true, 'windows' => ['general', 'acme--i1']], $this->json($run));
    }

    public function testResetOfOneWindowInPlaceOfGeneralOfAPaneAndOfAnUnknownInstance(): void
    {
        $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running]);
        $runner = $this->runner();
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\nacme--i1\n"));
        for ($i = 0; $i < 3; ++$i) {
            $runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $this->wholeWindow()));
        }

        $run = $this->cli('fleet:tmux:reset', ['window' => 'acme--i1']);
        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        self::assertCount(3, $runner->callsMatching(['tmux', '-L', 'fleet', 'respawn-pane']));
        self::assertCount(0, $runner->callsMatching(['tmux', '-L', 'fleet', 'kill-window']), 'rebuilt in place');

        $runner->expect(['tmux', '-L', 'fleet', 'display-message'], new CommandResult(0, "general\n"));
        $runner->expect(['tmux', '-L', 'fleet', 'list-windows'], new CommandResult(0, "general\n"));
        for ($i = 0; $i < 3; ++$i) {
            $runner->expect(['tmux', '-L', 'fleet', 'list-panes'], new CommandResult(0, $this->wholeWindow()));
        }
        $viaPane = $this->cli('fleet:tmux:reset', ['--pane' => '%7']);
        self::assertSame(0, $viaPane->getStatusCode(), $viaPane->getDisplay().$viaPane->getErrorOutput());

        $unknown = $this->cli('fleet:tmux:reset', ['window' => 'nope--x']);
        self::assertSame(1, $unknown->getStatusCode());
    }

    // --- fleet:tmux:sidebar

    public function testTheSidebarListsEveryInstanceWithTheCurrentOneHighlightedAndItsLiveBranch(): void
    {
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running, InstanceState::Stopped]);
        $this->runner()->expect(['git', 'for-each-ref'], new CommandResult(0, "*develop\n feature/FLE-1\n"));

        $run = $this->cli('fleet:tmux:sidebar', ['--window' => $instances[0]->id(), '--once' => true]);

        self::assertSame(0, $run->getStatusCode(), $run->getErrorOutput());
        $text = $run->getDisplay();
        self::assertStringContainsString("○ general\n", $text);
        self::assertStringContainsString("\e[7m● acme--i1", $text, 'the current window is reverse-highlighted');
        self::assertStringContainsString('○ acme--i2', $text);
        self::assertStringContainsString("\e[2mdevelop\e[0m", $text, 'the live branch under the instance');
        self::assertStringContainsString('url      https://acme--i1.fleet.example.com', $text);
        self::assertStringContainsString('project  acme', $text);
        self::assertStringContainsString('^b R  reset tab', $text);
        self::assertStringContainsString('reattach: fleet tmux', $text);
    }

    public function testTheSidebarOfGeneralAGoneInstanceAndABadWindow(): void
    {
        $general = $this->cli('fleet:tmux:sidebar', ['--window' => 'general', '--once' => true]);
        self::assertSame(0, $general->getStatusCode());
        self::assertStringContainsString("\e[7m○ general", $general->getDisplay());

        $gone = $this->cli('fleet:tmux:sidebar', ['--window' => 'acme--gone', '--once' => true]);
        self::assertSame(0, $gone->getStatusCode());
        self::assertStringContainsString('no longer exists', $gone->getDisplay());

        self::assertSame(2, $this->cli('fleet:tmux:sidebar', ['--window' => '--bad', '--once' => true])->getStatusCode());
        self::assertSame(2, $this->cli('fleet:tmux:sidebar', ['--once' => true])->getStatusCode());
    }

    public function testTheSidebarShowsTheRebootBannerWrapsLongBranchesAndCachesGit(): void
    {
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Failed, InstanceState::Deploying]);
        $flag = sys_get_temp_dir().'/reboot-'.bin2hex(random_bytes(4));
        touch($flag);
        $long = 'feature/FLE-1-'.str_repeat('abcdefghij', 6);
        $runner = $this->runner();
        $runner->expect(['git', 'for-each-ref'], new CommandResult(0, '*'.$long."\n"));
        $renderer = new \App\Fleet\Workspace\SidebarRenderer(
            self::getContainer()->get(InstanceRepository::class),
            self::getContainer()->get(\App\Fleet\Instances\InstanceHostnames::class),
            self::getContainer()->get(\App\Fleet\Git\GitClient::class),
            self::getContainer()->get(FleetPaths::class),
            new \App\Fleet\Host\RebootSignal($flag, $flag.'.pkgs'),
        );
        $cache = new \App\Fleet\Workspace\SidebarGitCache();

        try {
            $text = $renderer->render('general', $cache, 1000);
            $again = $renderer->render('general', $cache, 1100);
        } finally {
            unlink($flag);
        }

        self::assertStringContainsString('! reboot required', $text);
        self::assertStringContainsString('! acme--i1', $text, 'failed');
        self::assertStringContainsString('• acme--i2', $text, 'deploying');
        foreach (explode("\n", $text) as $line) {
            self::assertLessThanOrEqual(50, mb_strlen((string) preg_replace('/\e\[[0-9;]*m/', '', $line)), 'every line fits the pane');
        }
        self::assertStringContainsString('abcdefghij', $text);
        self::assertCount(2, $runner->callsMatching(['git', 'for-each-ref']), 'one read per instance; the second tick within five minutes reads no git');
        self::assertSame($text, $again);
        self::assertNotEmpty($instances);
    }
}
