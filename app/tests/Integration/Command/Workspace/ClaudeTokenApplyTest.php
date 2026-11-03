<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Workspace;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Job\Job;
use App\Fleet\Audit\AuditLog;
use App\Fleet\FleetPaths;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\FleetDatabase;
use App\Tests\Support\InstanceFixtures;
use App\Tests\Support\RunsFleetCommands;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * fleet claude:token:apply and claude:token:set --apply: running instances get one restart job each, one at a time,
 * and only when someone asks. The jobs run at once here (the test queue is synchronous) against the recording runner.
 */
final class ClaudeTokenApplyTest extends KernelTestCase
{
    use FleetDatabase;
    use InstanceFixtures;
    use RunsFleetCommands;

    private const string TOKEN = 'sk-ant-oat01-apply-unique-0123456789';

    private EntityManagerInterface $em;
    private FleetPaths $fleetPaths;
    private RecordingCommandRunner $runner;

    protected function setUp(): void
    {
        $this->em = $this->setUpFleetDatabase();
        [$exit, $text] = $this->console('fleet:host:init', ['--domain' => 'fleet.example.com']);
        self::assertSame(0, $exit, $text);
        $this->fleetPaths = self::getContainer()->get(FleetPaths::class);
        $runner = self::getContainer()->get(CommandRunner::class);
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);
        $this->runner = $runner;
        $this->runner->reset();
        self::getContainer()->get(SecretStore::class)->putFleetWide('claude_oauth_token', self::TOKEN, 'admin');
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * Instances i1..: running, stopped, running, with a checkout (and a stale config.fleet.yaml) for each.
     *
     * @return list<string> the ids
     */
    private function fleet(): array
    {
        $instances = $this->instancesOf($this->projectNamed('acme'), [InstanceState::Running, InstanceState::Stopped, InstanceState::Running]);
        $ids = [];
        foreach ($instances as $instance) {
            $dir = $this->fleetPaths->instanceDir($instance->id());
            mkdir($dir.'/.ddev', 0o750, true);
            file_put_contents($dir.'/.ddev/config.fleet.yaml', "web_environment:\n  - CLAUDE_CODE_OAUTH_TOKEN=old\n");
            $ids[] = $instance->id();
        }

        return $ids;
    }

    /**
     * @return list<string> the checkouts `ddev restart` ran in, in order
     */
    private function restarted(): array
    {
        return array_map(
            static fn ($call): string => (string) $call->cwd,
            $this->runner->callsMatching(['ddev', 'restart']),
        );
    }

    /**
     * @return list<Job>
     */
    private function jobs(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Job::class)->findBy([], ['id' => 'ASC']);
    }

    public function testAllRestartsEveryRunningInstanceOneByOneWithTheStoredToken(): void
    {
        [$one, $two, $three] = $this->fleet();

        $run = $this->cli('fleet:claude:token:apply', ['--all' => true, '--yes' => true]);

        self::assertSame(0, $run->getStatusCode(), $run->getDisplay().$run->getErrorOutput());
        self::assertSame([$this->fleetPaths->instanceDir($one), $this->fleetPaths->instanceDir($three)], $this->restarted());
        foreach ([$one, $three] as $id) {
            self::assertStringContainsString('CLAUDE_CODE_OAUTH_TOKEN='.self::TOKEN, (string) file_get_contents($this->fleetPaths->instanceDir($id).'/.ddev/config.fleet.yaml'));
        }
        self::assertStringContainsString('=old', (string) file_get_contents($this->fleetPaths->instanceDir($two).'/.ddev/config.fleet.yaml'), 'a stopped instance is left alone');

        $jobs = $this->jobs();
        self::assertSame(['restart', 'restart'], array_map(static fn (Job $j): string => $j->type(), $jobs));
        self::assertSame([$one, $three], array_map(static fn (Job $j): ?string => $j->instanceId(), $jobs));
        self::assertNotNull($jobs[0]->batchId());
        self::assertSame($jobs[0]->batchId(), $jobs[1]->batchId());
        self::assertTrue($jobs[0]->isSequential());
        self::assertStringNotContainsString(self::TOKEN, $run->getDisplay().$run->getErrorOutput());
        self::assertSame([], array_filter($jobs, static fn (Job $j): bool => str_contains(json_encode($j->params(), \JSON_THROW_ON_ERROR), self::TOKEN)));
    }

    public function testAllNeedsConfirmation(): void
    {
        $this->fleet();

        $silent = $this->cli('fleet:claude:token:apply', ['--all' => true]);
        $declined = $this->cli('fleet:claude:token:apply', ['--all' => true], ['no'], true);

        foreach ([$silent, $declined] as $run) {
            self::assertSame(1, $run->getStatusCode());
            self::assertStringContainsString('pass --yes', $run->getErrorOutput().$run->getDisplay());
        }
        self::assertSame([], $this->restarted());
        self::assertSame([], $this->jobs());

        $accepted = $this->cli('fleet:claude:token:apply', ['--all' => true], ['yes'], true);

        self::assertSame(0, $accepted->getStatusCode(), $accepted->getDisplay().$accepted->getErrorOutput());
        self::assertCount(2, $this->jobs());
    }

    public function testOneInstanceAndTheAuditEntry(): void
    {
        [$one] = $this->fleet();

        $run = $this->cli('fleet:claude:token:apply', ['--instance' => [$one], '--json' => true]);

        self::assertSame(0, $run->getStatusCode(), $run->getErrorOutput());
        self::assertSame([$this->fleetPaths->instanceDir($one)], $this->restarted());
        self::assertSame($one, $this->json($run)[0]['instance']);
        $audit = array_map(static fn ($e): array => [$e->actor(), $e->action(), $e->subject()], new AuditEntryRepository($this->em)->latest(10));
        self::assertContains(['cli', AuditLog::CLAUDE_TOKEN_APPLY, 'fleet/claude_oauth_token'], $audit);
        self::assertStringNotContainsString(self::TOKEN, json_encode($audit, \JSON_THROW_ON_ERROR));
    }

    public function testNothingIsRestartedWithoutAChoice(): void
    {
        $this->fleet();

        $run = $this->cli('fleet:claude:token:apply');

        self::assertSame(2, $run->getStatusCode());
        self::assertSame([], $this->restarted());
        self::assertSame([], $this->jobs());
    }

    public function testAStoppedOrUnknownInstanceIsRefusedAndNothingIsQueued(): void
    {
        [$one, $two] = $this->fleet();

        $stopped = $this->cli('fleet:claude:token:apply', ['--instance' => [$one, $two]]);
        $unknown = $this->cli('fleet:claude:token:apply', ['--instance' => ['acme--nope']]);

        self::assertSame(1, $stopped->getStatusCode());
        self::assertStringContainsString('not running', $stopped->getErrorOutput().$stopped->getDisplay());
        self::assertSame(1, $unknown->getStatusCode());
        self::assertSame([], $this->restarted());
        self::assertSame([], $this->jobs());
    }

    public function testAllWithNoRunningInstance(): void
    {
        $run = $this->cli('fleet:claude:token:apply', ['--all' => true, '--yes' => true]);

        self::assertSame(0, $run->getStatusCode());
        self::assertStringContainsString('No instance is running', $run->getDisplay());
    }

    public function testAFailedRestartFailsOnlyItsJob(): void
    {
        [$one, , $three] = $this->fleet();
        $this->runner->expect(['ddev', 'restart'], new CommandResult(1, '', 'docker is down'));

        $this->cli('fleet:claude:token:apply', ['--all' => true, '--yes' => true]);

        $jobs = $this->jobs();
        self::assertSame('failed', $jobs[0]->status()->value);
        self::assertSame('succeeded', $jobs[1]->status()->value);
        self::assertSame([$one, $three], array_map(static fn (Job $j): ?string => $j->instanceId(), $jobs));
    }

    public function testTokenSetAppliesOnlyWhenAskedTo(): void
    {
        $this->fleet();
        $this->runner->reset();

        $plain = $this->cli('fleet:claude:token:set', [], [self::TOKEN."\n"]);
        self::assertSame(0, $plain->getStatusCode(), $plain->getErrorOutput());
        self::assertSame([], $this->restarted(), 'a rotation never restarts by itself');
        self::assertStringContainsString('fleet claude:token:apply', $plain->getDisplay());

        $apply = $this->cli('fleet:claude:token:set', ['--apply' => true], [self::TOKEN."\n"]);
        self::assertSame(0, $apply->getStatusCode(), $apply->getErrorOutput());
        self::assertCount(2, $this->restarted());
        self::assertStringNotContainsString(self::TOKEN, $apply->getDisplay().$apply->getErrorOutput());
    }
}
