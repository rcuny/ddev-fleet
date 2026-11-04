<?php

declare(strict_types=1);

namespace App\Tests\Functional\Upgrade;

use App\Command\Upgrade\UpgradeCheckCommand;
use App\Command\Upgrade\UpgradePlanCommand;
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
use App\Fleet\Upgrade\UpgradeAssessor;
use App\Fleet\Upgrade\UpgradePlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Process\Process;

/**
 * `fleet:upgrade:check` and `fleet:upgrade:plan` through a CommandTester over a real temporary bare repository (the
 * "fake catalog"), a real catalog cache and a scripted pre-flight registry.
 */
final class UpgradeCheckCommandTest extends TestCase
{
    private string $dir;
    private string $remote;
    private MockClock $clock;
    private ArrayAdapter $cache;
    /** @var list<CheckResult> */
    private array $findings = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/upgrade-check-'.bin2hex(random_bytes(4));
        $work = $this->dir.'/work';
        $this->remote = $this->dir.'/remote.git';
        mkdir($work, 0o777, true);
        $this->git($work, 'init', '-q', '-b', 'main');
        $this->git($work, 'config', 'user.name', 'T');
        $this->git($work, 'config', 'user.email', 't@example.test');
        $this->git($work, 'config', 'commit.gpgsign', 'false');
        $this->git($work, 'config', 'tag.gpgsign', 'false');
        $one = "## [1.1.0] - 2026-01-01\n\n### Added\n- one\n";
        $two = "## [1.2.0] - 2026-02-01\n\n### Added\n- two\n\n#### Rollout\n- ACTION: rotate the token\n- plain note\n\n";
        $three = "## [1.2.1] - 2026-03-01\n\n### Fixed\n- three\n\n";
        $this->release($work, '1.1.0', "# Changelog\n\n".$one);
        $this->release($work, '1.2.0', "# Changelog\n\n".$two.$one);
        $this->release($work, '1.2.1', "# Changelog\n\n".$three.$two.$one);
        $this->git($this->dir, 'clone', '-q', '--bare', $work, $this->remote);
        $this->clock = new MockClock('2026-11-03 10:00:00 UTC');
        $this->cache = new ArrayAdapter();
        $this->findings = [];
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

    private function assessor(string $installed, ?string $url = null): UpgradeAssessor
    {
        $versionFile = $this->dir.'/installed-version';
        file_put_contents($versionFile, $installed."\n");
        $runner = new ProcessCommandRunner();
        $repo = new RepoUrl($url ?? $this->remote);
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

        return new UpgradeAssessor(
            new InstalledVersion($runner, $versionFile, $this->dir.'/no-checkout'),
            new ReleaseCatalog($this->cache, new GitRemoteTags($runner, $repo), $this->clock),
            new UpgradePlanner(),
            new ChangelogReader(new MockHttpClient(), $runner, $repo),
            new CheckRunner([$check]),
            $this->clock,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function check(UpgradeAssessor $assessor, array $input = []): CommandTester
    {
        $tester = new CommandTester(new UpgradeCheckCommand($assessor));
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testOneReleaseBehindShowsBothVersionsTheChangelogRolloutNotesAndTheCheckTable(): void
    {
        $this->findings = [CheckResult::warn('disk.low', 'Little disk left.', 'Free some space.')];

        $tester = $this->check($this->assessor('v1.1.0'));
        $out = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $out);
        self::assertStringContainsString('Installed: v1.1.0', $out);
        self::assertStringContainsString('Latest:    v1.2.1', $out);
        self::assertStringContainsString('Update available: v1.1.0 -> v1.2.1 (2 releases).', $out);
        self::assertStringContainsString('## v1.2.1 - 2026-03-01', $out);
        self::assertStringContainsString('## v1.2.0 - 2026-02-01', $out);
        self::assertStringNotContainsString('## v1.1.0', $out, 'the installed version is not part of the excerpt');
        self::assertStringContainsString('Rollout notes', $out);
        self::assertStringContainsString('- ACTION: rotate the token', $out);
        self::assertStringContainsString('WARN  disk.low: Little disk left.', $out);
        self::assertStringContainsString('To upgrade: sudo fleet upgrade', $out);
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testCurrentPrintsUpToDateAndExitsZero(): void
    {
        $tester = $this->check($this->assessor('v1.2.1'));

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Already up to date.', $tester->getDisplay());
        self::assertStringNotContainsString('Pre-flight', $tester->getDisplay());
    }

    public function testABlockingFindingExitsThree(): void
    {
        $this->findings = [CheckResult::block('checkout.dirty', 'The checkout was modified.', 'Discard the changes.')];

        $tester = $this->check($this->assessor('v1.1.0'));

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('BLOCK checkout.dirty: The checkout was modified.', $tester->getDisplay());
        self::assertStringContainsString('Blocked:', $tester->getDisplay());
        self::assertStringNotContainsString('To upgrade:', $tester->getDisplay());
    }

    public function testOfflinePrintsTheInstalledAndTheCachedLatestWithAgeAndExitsFour(): void
    {
        $this->check($this->assessor('v1.1.0')); // fills the cache
        $this->clock->modify('+3 hours');

        $tester = $this->check($this->assessor('v1.1.0', $this->dir.'/gone.git'), ['--no-cache' => true]);
        $out = $tester->getDisplay();

        self::assertSame(4, $tester->getStatusCode(), $out);
        self::assertStringContainsString('Installed: v1.1.0', $out);
        self::assertStringContainsString('Latest:    v1.2.1 (listed 3 h ago)', $out);
        self::assertStringContainsString('Could not read the release source:', $out);
        self::assertStringContainsString('Showing the cached list', $out);
        self::assertStringNotContainsString('Stack trace', $out.$tester->getErrorOutput());
        self::assertStringNotContainsString('Exception', $out.$tester->getErrorOutput());
    }

    public function testOfflineWithNothingCachedExitsFourToo(): void
    {
        $tester = $this->check($this->assessor('v1.1.0', $this->dir.'/gone.git'));

        self::assertSame(4, $tester->getStatusCode());
        self::assertStringContainsString('Latest:    unknown', $tester->getDisplay());
        self::assertStringContainsString('No cached release list', $tester->getDisplay());
    }

    public function testAnUnknownTargetIsAUsageErrorListingTheAvailableTags(): void
    {
        $tester = $this->check($this->assessor('v1.1.0'), ['--to' => 'v1.9.9']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('v1.9.9 is not a published release; available: v1.2.1, v1.2.0, v1.1.0', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testATargetThatIsNotAVersionIsAUsageErrorToo(): void
    {
        $tester = $this->check($this->assessor('v1.1.0'), ['--to' => 'main']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('"main" is not a version', $tester->getErrorOutput());
        self::assertStringContainsString('v1.2.1', $tester->getErrorOutput());
    }

    public function testAnExplicitOlderTargetIsShownAsADowngrade(): void
    {
        $tester = $this->check($this->assessor('v1.2.1'), ['--to' => 'v1.1.0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('This is a downgrade: v1.2.1 -> v1.1.0', $tester->getDisplay());
    }

    public function testJsonHasAStableSchema(): void
    {
        $this->findings = [CheckResult::warn('rollout.action', 'Needs you.', 'Do it.')];

        $tester = $this->check($this->assessor('v1.1.0'), ['--json' => true]);
        $doc = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['schema', 'status', 'exitCode', 'installed', 'target', 'latest', 'direction', 'releases', 'catalog', 'changelog', 'changelogError', 'rollout', 'checks', 'checksSkipped'],
            array_keys($doc),
        );
        self::assertSame(1, $doc['schema']);
        self::assertSame('update_available', $doc['status']);
        self::assertSame(0, $doc['exitCode']);
        self::assertSame(['raw' => 'v1.1.0', 'version' => 'v1.1.0'], $doc['installed']);
        self::assertSame('v1.2.1', $doc['target']['tag']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $doc['target']['sha']);
        self::assertSame('up', $doc['direction']);
        self::assertSame(['v1.2.1', 'v1.2.0'], $doc['releases']);
        self::assertSame(['checkedAt', 'listedAt', 'ageSeconds', 'error', 'refreshed'], array_keys($doc['catalog']));
        self::assertTrue($doc['catalog']['refreshed']);
        self::assertNull($doc['catalog']['error']);
        self::assertSame(['version', 'date', 'body', 'rollout'], array_keys($doc['changelog'][0]));
        self::assertSame(['v1.2.1', 'v1.2.0'], array_column($doc['changelog'], 'version'));
        self::assertSame(['ACTION: rotate the token', 'plain note'], $doc['rollout']['notes']);
        self::assertSame(['rotate the token'], $doc['rollout']['actions']);
        self::assertSame(['id', 'severity', 'message', 'remedy', 'overridable'], array_keys($doc['checks'][0]));
        self::assertSame([], $doc['checksSkipped']);
    }

    public function testJsonReportsOfflineAndUsageErrorsAsDocuments(): void
    {
        $offline = $this->check($this->assessor('v1.1.0', $this->dir.'/gone.git'), ['--json' => true]);
        $doc = json_decode($offline->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(4, $offline->getStatusCode());
        self::assertSame('offline', $doc['status']);
        self::assertSame(4, $doc['exitCode']);
        self::assertNotNull($doc['catalog']['error']);

        $usage = $this->check($this->assessor('v1.1.0'), ['--json' => true, '--to' => 'v1.9.9']);
        $err = json_decode($usage->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(2, $usage->getStatusCode());
        self::assertSame(['schema', 'error', 'exitCode'], array_keys($err));
    }

    public function testTheCatalogCacheIsUsedUnlessNoCacheIsGiven(): void
    {
        $this->check($this->assessor('v1.1.0'));
        $this->clock->modify('+1 hour');

        $cached = json_decode($this->check($this->assessor('v1.1.0'), ['--json' => true])->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $fresh = json_decode($this->check($this->assessor('v1.1.0'), ['--json' => true, '--no-cache' => true])->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertFalse($cached['catalog']['refreshed']);
        self::assertSame(3600, $cached['catalog']['ageSeconds']);
        self::assertTrue($fresh['catalog']['refreshed']);
        self::assertSame(0, $fresh['catalog']['ageSeconds']);
    }

    public function testThePlanCommandPrintsTheSameDocumentAndWaivesOnlyForcedOverridableBlocks(): void
    {
        $this->findings = [CheckResult::block('active.jobs', 'Jobs running.', 'Wait.', true)];
        $assessor = $this->assessor('v1.1.0');

        $plain = new CommandTester(new UpgradePlanCommand($assessor));
        $plain->execute([]);
        self::assertSame(3, $plain->getStatusCode());
        self::assertSame('blocked', json_decode($plain->getDisplay(), true, 512, \JSON_THROW_ON_ERROR)['status']);

        $forced = new CommandTester(new UpgradePlanCommand($assessor));
        $forced->execute(['--force' => ['active.jobs']]);
        $doc = json_decode($forced->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $forced->getStatusCode());
        self::assertSame('update_available', $doc['status']);
        self::assertSame('v1.2.1', $doc['target']['tag']);
    }

    public function testControlCharactersFromAChangelogNeverReachTheTerminal(): void
    {
        $this->findings = [CheckResult::warn('x.y', "Bad \e[31mcolour\e[0m text", "remedy\x07")];

        $tester = $this->check($this->assessor('v1.1.0'));

        self::assertStringNotContainsString("\e", $tester->getDisplay());
        self::assertStringNotContainsString("\x07", $tester->getDisplay());
        self::assertStringContainsString('Bad [31mcolour[0m text', $tester->getDisplay());
    }
}
