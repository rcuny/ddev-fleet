<?php

declare(strict_types=1);

namespace App\Tests\Functional\Upgrade;

use App\Command\Upgrade\UpgradeBackupCommand;
use App\Command\Upgrade\UpgradePrepareCommand;
use App\Command\Upgrade\UpgradeVerifyCommand;
use App\Fleet\Shell\ProcessCommandRunner;
use App\Fleet\Upgrade\ChangelogReader;
use App\Fleet\Upgrade\Check\CheckResult;
use App\Fleet\Upgrade\Check\CheckRunner;
use App\Fleet\Upgrade\Check\UpgradeCheck;
use App\Fleet\Upgrade\GitRemoteTags;
use App\Fleet\Upgrade\InstalledVersion;
use App\Fleet\Upgrade\Plan;
use App\Fleet\Upgrade\ReleaseCatalog;
use App\Fleet\Upgrade\RepoUrl;
use App\Fleet\Upgrade\RunState;
use App\Fleet\Upgrade\UpgradeAssessor;
use App\Fleet\Upgrade\UpgradeBackup;
use App\Fleet\Upgrade\UpgradePlanner;
use App\Fleet\Upgrade\UpgradeRunner;
use App\Fleet\Upgrade\UpgradeVerification;
use App\Fleet\Upgrade\VerifyReport;
use App\Fleet\Upgrade\VerifyResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Process\Process;

/**
 * The fleet-user phases of `sudo fleet upgrade`: UpgradeRunner (prepare / backup / verify) and the three internal
 * commands, over a real temporary bare repository as the release source, a scripted pre-flight, and fake backup and
 * verification seams. Success, blocked, offline, nothing to do, downgrade, usage, backup failure and verify failure.
 */
final class UpgradeRunnerTest extends TestCase
{
    private string $dir;
    private string $remote;
    private string $v121Sha;
    /** @var list<CheckResult> */
    private array $findings = [];
    private bool $backupFails = false;
    private int $backups = 0;
    /** @var list<VerifyResult> */
    private array $verifyResults = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/upgrade-runner-'.bin2hex(random_bytes(4));
        $work = $this->dir.'/work';
        $this->remote = $this->dir.'/remote.git';
        mkdir($work, 0o777, true);
        $this->git($work, 'init', '-q', '-b', 'main');
        $this->git($work, 'config', 'user.name', 'T');
        $this->git($work, 'config', 'user.email', 't@example.test');
        $this->git($work, 'config', 'commit.gpgsign', 'false');
        $this->git($work, 'config', 'tag.gpgsign', 'false');
        $one = "## [1.1.0] - 2026-01-01\n\n### Added\n- one\n";
        $two = "## [1.2.0] - 2026-02-01\n\n### Added\n- two\n\n#### Rollout\n- ACTION: rotate the token\n\n";
        $three = "## [1.2.1] - 2026-03-01\n\n### Fixed\n- three\n\n";
        $this->release($work, '1.1.0', "# Changelog\n\n".$one);
        $this->release($work, '1.2.0', "# Changelog\n\n".$two.$one);
        $this->release($work, '1.2.1', "# Changelog\n\n".$three.$two.$one);
        $this->git($this->dir, 'clone', '-q', '--bare', $work, $this->remote);
        $this->v121Sha = trim(new Process(['git', '-C', $work, 'rev-parse', 'v1.2.1^{commit}'])->mustRun()->getOutput());
        $this->findings = [];
        $this->backupFails = false;
        $this->backups = 0;
        $this->verifyResults = [new VerifyResult('health', true, 'answers')];
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    private function git(string $cwd, string ...$args): void
    {
        new Process(['git', ...$args], $cwd)->mustRun();
    }

    private function release(string $work, string $version, string $changelog): void
    {
        file_put_contents($work.'/CHANGELOG.md', $changelog);
        $this->git($work, 'add', '.');
        $this->git($work, 'commit', '-q', '-m', 'release '.$version);
        $this->git($work, 'tag', '-a', 'v'.$version, '-m', 'release '.$version);
    }

    private function runner(string $installed, ?string $url = null): UpgradeRunner
    {
        $versionFile = $this->dir.'/installed-version';
        file_put_contents($versionFile, $installed."\n");
        $process = new ProcessCommandRunner();
        $repo = new RepoUrl($url ?? $this->remote);
        $clock = new MockClock('2026-11-03 10:00:00 UTC');
        $findings = &$this->findings;
        $check = new class($findings) implements UpgradeCheck {
            /** @param list<CheckResult> $findings */
            public function __construct(private array &$findings)
            {
            }

            public function check(Plan $plan): array
            {
                return $this->findings;
            }
        };
        $assessor = new UpgradeAssessor(
            new InstalledVersion($process, $versionFile, $this->dir.'/no-checkout'),
            new ReleaseCatalog(new ArrayAdapter(), new GitRemoteTags($process, $repo), $clock),
            new UpgradePlanner(),
            new ChangelogReader(new MockHttpClient(), $process, $repo),
            new CheckRunner([$check]),
            $clock,
        );
        $self = $this;
        $backup = new class($self) implements UpgradeBackup {
            public function __construct(private UpgradeRunnerTest $test)
            {
            }

            public function create(): string
            {
                return $this->test->takeBackup();
            }
        };
        $verification = new class($self) implements UpgradeVerification {
            public function __construct(private UpgradeRunnerTest $test)
            {
            }

            public function verify(string $from, string $targetTag, ?string $targetSha, string $actor): VerifyReport
            {
                return new VerifyReport($this->test->verifyResults());
            }
        };

        return new UpgradeRunner($assessor, $backup, $verification);
    }

    /** @internal for the fake seams above */
    public function takeBackup(): string
    {
        ++$this->backups;
        if ($this->backupFails) {
            throw new \RuntimeException('disk full');
        }

        return 'fleet-20261103-100000.sqlite';
    }

    /**
     * @internal for the fake seams above
     *
     * @return list<VerifyResult>
     */
    public function verifyResults(): array
    {
        return $this->verifyResults;
    }

    public function testOneReleaseBehindIsReadyWithTheTargetAndItsCommit(): void
    {
        $prep = $this->runner('v1.1.0')->prepare(null);

        self::assertSame(RunState::Ready, $prep->state);
        self::assertSame(0, $prep->exitCode());
        self::assertSame('v1.1.0', $prep->from);
        self::assertSame('v1.2.1', $prep->target?->tag());
        self::assertSame($this->v121Sha, $prep->target->sha);
        self::assertSame(['rotate the token'], $prep->actions);
    }

    public function testAnExplicitTargetIsPlanned(): void
    {
        $prep = $this->runner('v1.1.0')->prepare('v1.2.0');

        self::assertSame(RunState::Ready, $prep->state);
        self::assertSame('v1.2.0', $prep->target?->tag());
    }

    public function testABlockIsRefusedWithItsRemedyAndNothingElseRuns(): void
    {
        $this->findings = [CheckResult::block('checkout.dirty', 'The checkout was modified.', 'Discard the changes.')];

        $prep = $this->runner('v1.1.0')->prepare(null);

        self::assertSame(RunState::Refused, $prep->state);
        self::assertSame(3, $prep->exitCode());
        self::assertSame(['[checkout.dirty] The checkout was modified. Discard the changes.'], $prep->reasons);
        self::assertSame(0, $this->backups);
    }

    public function testAnOverridableBlockIsWaivedByForce(): void
    {
        $this->findings = [CheckResult::block('jobs.active', 'Jobs are running.', 'Wait.', true)];

        self::assertSame(RunState::Refused, $this->runner('v1.1.0')->prepare(null)->state);
        self::assertSame(RunState::Ready, $this->runner('v1.1.0')->prepare(null, ['jobs.active'])->state);
    }

    public function testANonOverridableBlockSurvivesForce(): void
    {
        $this->findings = [CheckResult::block('checkout.dirty', 'The checkout was modified.', 'Discard.')];

        self::assertSame(RunState::Refused, $this->runner('v1.1.0')->prepare(null, ['checkout.dirty'])->state);
    }

    public function testAnUnreachableReleaseSourceIsRefused(): void
    {
        $prep = $this->runner('v1.1.0', $this->dir.'/gone.git')->prepare(null);

        self::assertSame(RunState::Refused, $prep->state);
        self::assertSame(3, $prep->exitCode());
        self::assertStringContainsString('release source could not be read', $prep->reasons[0]);
    }

    public function testNothingToUpgradeIsASuccess(): void
    {
        $prep = $this->runner('v1.2.1')->prepare(null);

        self::assertSame(RunState::UpToDate, $prep->state);
        self::assertSame(0, $prep->exitCode());
    }

    public function testADowngradeIsRefused(): void
    {
        $prep = $this->runner('v1.2.1')->prepare('v1.1.0');

        self::assertSame(RunState::Refused, $prep->state);
        self::assertStringContainsString('rollback', $prep->reasons[0]);
    }

    public function testAHostThatRunsABranchIsRefused(): void
    {
        $prep = $this->runner('main@abc1234')->prepare(null);

        self::assertSame(RunState::Refused, $prep->state);
        self::assertStringContainsString('not a release', $prep->reasons[0]);
    }

    public function testAnUnknownTargetIsAUsageError(): void
    {
        $prep = $this->runner('v1.1.0')->prepare('v1.9.9');

        self::assertSame(RunState::Usage, $prep->state);
        self::assertSame(2, $prep->exitCode());
        self::assertStringContainsString('available: v1.2.1, v1.2.0, v1.1.0', $prep->reasons[0]);
    }

    public function testTheBackupNameIsReturnedAndAFailureThrows(): void
    {
        $runner = $this->runner('v1.1.0');
        self::assertSame('fleet-20261103-100000.sqlite', $runner->backup());

        $this->backupFails = true;
        $this->expectException(\RuntimeException::class);
        $runner->backup();
    }

    public function testVerifyPassesAndFailsOnACriticalStep(): void
    {
        $runner = $this->runner('v1.2.1');
        self::assertTrue($runner->verify('v1.1.0', 'v1.2.1', $this->v121Sha, 'alice')->passed());

        $this->verifyResults = [new VerifyResult('health', true, 'answers'), new VerifyResult('audit', false, 'no row', false)];
        self::assertTrue($runner->verify('v1.1.0', 'v1.2.1', null, 'alice')->passed(), 'a failed best-effort step only warns');

        $this->verifyResults = [new VerifyResult('health', false, 'down')];
        self::assertFalse($runner->verify('v1.1.0', 'v1.2.1', null, 'alice')->passed());
    }

    // --- the internal commands --------------------------------------------------------------------------------------

    public function testThePrepareCommandPrintsOnlyKeyValueLinesOnStdout(): void
    {
        $tester = new CommandTester(new UpgradePrepareCommand($this->runner('v1.1.0')));
        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("STATE=ready\nFROM=v1.1.0\nTO=v1.2.1\nSHA={$this->v121Sha}\nACTIONS=1\n", $tester->getDisplay());
        self::assertStringContainsString('Update available: v1.1.0 -> v1.2.1', $tester->getErrorOutput());
    }

    public function testThePrepareCommandExitsThreeForABlockAndReportsOnStderr(): void
    {
        $this->findings = [CheckResult::block('disk.space', 'Not enough room.', 'Free 2 GB.')];

        $tester = new CommandTester(new UpgradePrepareCommand($this->runner('v1.1.0')));
        $tester->execute(['--to' => 'v1.2.1'], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame("STATE=refused\nFROM=v1.1.0\n", $tester->getDisplay());
        self::assertStringContainsString('[disk.space] Not enough room. Free 2 GB.', $tester->getErrorOutput());
    }

    public function testThePrepareCommandUsageErrorIsExitTwo(): void
    {
        $tester = new CommandTester(new UpgradePrepareCommand($this->runner('v1.1.0')));
        $tester->execute(['--to' => 'nonsense'], ['capture_stderr_separately' => true]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringStartsWith('STATE=usage', $tester->getDisplay());
    }

    public function testTheBackupCommandPrintsTheNameOrFailsWithExitOne(): void
    {
        $tester = new CommandTester(new UpgradeBackupCommand($this->runner('v1.1.0')));
        $tester->execute([], ['capture_stderr_separately' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("BACKUP=fleet-20261103-100000.sqlite\n", $tester->getDisplay());

        $this->backupFails = true;
        $tester->execute([], ['capture_stderr_separately' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('nothing was changed: disk full', $tester->getErrorOutput());
    }

    public function testTheVerifyCommandExitsZeroWhenVerifiedAndFiveWithTheRollbackCommandWhenNot(): void
    {
        $tester = new CommandTester(new UpgradeVerifyCommand($this->runner('v1.2.1')));
        $tester->execute(['--to' => 'v1.2.1', '--from' => 'v1.1.0', '--sha' => $this->v121Sha, '--actor' => 'alice']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ok   health', $tester->getDisplay());

        $this->verifyResults = [new VerifyResult('health', false, 'does not answer')];
        $tester->execute(['--to' => 'v1.2.1', '--from' => 'v1.1.0']);
        self::assertSame(5, $tester->getStatusCode());
        self::assertStringContainsString('FAIL health', $tester->getDisplay());
        self::assertStringContainsString('To go back: sudo fleet upgrade --to v1.1.0', $tester->getDisplay());
    }

    public function testTheVerifyCommandNeedsAVersion(): void
    {
        $tester = new CommandTester(new UpgradeVerifyCommand($this->runner('v1.2.1')));
        $tester->execute([]);

        self::assertSame(2, $tester->getStatusCode());
    }
}
