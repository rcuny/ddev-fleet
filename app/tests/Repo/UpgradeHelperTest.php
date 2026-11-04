<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * install/libexec/upgrade, the root helper of `sudo fleet upgrade`, run unprivileged against a scratch tree
 * (FLEET_UPGRADE_TEST_ROOT) with a stub `fleet` (the console commands), a local git repository standing in for the
 * release source and a fake installer script. The real run is the rehearsal on a test host, not CI.
 */
final class UpgradeHelperTest extends TestCase
{
    use InstallerHarness;

    private string $root = '';
    private string $log = '';
    private string $repo = '';
    private string $sha = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The helper ignores FLEET_UPGRADE_TEST_ROOT as root, so these scratch-tree tests cannot run as root.');
        }
        $this->root = $this->scratch().'/root';
        $this->log = $this->scratch().'/calls.log';
        mkdir($this->root.'/etc/ddev-fleet', 0o755, true);
        file_put_contents($this->root.'/etc/ddev-fleet/fleet.env', "APP_ENV=prod\nDEFAULT_URI=https://fleet.example.com\n");
        touch($this->log);
        $this->makeRepo();
    }

    public function testAFullRunInstallsVerifiesAndWritesTheHistory(): void
    {
        [$status, $out, $err] = $this->upgrade(['--yes'], ['SUDO_USER' => 'alice']);

        self::assertSame(0, $status, $out.$err);
        self::assertStringContainsString('Upgraded v1.1.0 -> v1.2.1 and verified', $out);
        self::assertStringContainsString('/var/log/ddev-fleet-install.log', $out, 'the installer log path is printed');
        $calls = $this->calls();
        self::assertSame(['fleet upgrade:prepare', 'fleet upgrade:backup', 'installer --domain=fleet.example.com --ref=v1.2.1 --repo-url='.$this->repo.' --skip-dns-check', 'fleet upgrade:verify --to=v1.2.1 --from=v1.1.0 --sha='.$this->sha.' --actor=alice'], $calls);

        $history = $this->history();
        self::assertSame(['start', 'installer', 'verify'], array_column($history, 'event'));
        self::assertSame(['started', 'ok', 'ok'], array_column($history, 'result'));
        self::assertSame('fleet-20261104-100000.sqlite', $history[0]['backup']);
        foreach ($history as $line) {
            self::assertSame('v1.1.0', $line['from']);
            self::assertSame('v1.2.1', $line['to']);
            self::assertSame($this->sha, $line['sha']);
            self::assertSame('alice', $line['actor']);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $line['ts']);
        }
        self::assertSame('0644', substr(\sprintf('%o', fileperms($this->historyFile())), -4));
        self::assertSame([], glob($this->scratch().'/tmp/fleet-upgrade.*') ?: [], 'the temporary export is removed');
    }

    public function testTheInstallerIsTheOneOfThePlannedCommit(): void
    {
        $this->upgrade(['--yes']);

        self::assertStringContainsString('fake installer of v1.2.1', (string) file_get_contents($this->scratch().'/installer-ran'));
    }

    public function testAnUnknownSudoUserIsRecordedAsRoot(): void
    {
        $this->upgrade(['--yes'], ['SUDO_USER' => 'Mal"ory$(x)']);

        self::assertSame('root', $this->history()[0]['actor']);
    }

    public function testARefusedPlanChangesNothing(): void
    {
        [$status, , $err] = $this->upgrade(['--yes'], ['FAKE_PREPARE_STATE' => 'refused', 'FAKE_PREPARE_EXIT' => '3']);

        self::assertSame(3, $status);
        self::assertStringContainsString('nothing was changed', $err);
        self::assertSame(['fleet upgrade:prepare'], $this->calls());
        self::assertFileDoesNotExist($this->historyFile());
    }

    public function testNothingToUpgradeExitsZeroWithoutTouchingAnything(): void
    {
        [$status, $out] = $this->upgrade(['--yes'], ['FAKE_PREPARE_STATE' => 'up_to_date']);

        self::assertSame(0, $status);
        self::assertStringContainsString('nothing to upgrade', $out);
        self::assertSame(['fleet upgrade:prepare'], $this->calls());
    }

    public function testANonInteractiveRunWithoutYesIsRefusedBeforeTheBackup(): void
    {
        [$status, , $err] = $this->upgrade([]);

        self::assertSame(3, $status);
        self::assertStringContainsString('pass --yes', $err);
        self::assertSame(['fleet upgrade:prepare'], $this->calls());
        self::assertFileDoesNotExist($this->historyFile());
    }

    public function testAFailedBackupStopsBeforeTheInstallerAndLeavesNoHistory(): void
    {
        [$status, , $err] = $this->upgrade(['--yes'], ['FAKE_BACKUP_EXIT' => '1']);

        self::assertSame(1, $status);
        self::assertStringContainsString('nothing was changed', $err);
        self::assertSame(['fleet upgrade:prepare', 'fleet upgrade:backup'], $this->calls());
        self::assertFileDoesNotExist($this->historyFile());
        self::assertFileDoesNotExist($this->scratch().'/installer-ran');
    }

    public function testAMovedTagIsRefusedBeforeAnythingChanges(): void
    {
        [$status, , $err] = $this->upgrade(['--yes'], ['FAKE_SHA' => str_repeat('a', 40)]);

        self::assertSame(3, $status);
        self::assertStringContainsString('no longer points at the commit the plan resolved', $err);
        self::assertSame(['fleet upgrade:prepare'], $this->calls());
    }

    public function testAFailedInstallerIsRecordedAndExitsOne(): void
    {
        [$status, , $err] = $this->upgrade(['--yes'], ['FAKE_INSTALL_EXIT' => '7']);

        self::assertSame(1, $status);
        self::assertStringContainsString('the installer failed (exit 7)', $err);
        self::assertStringContainsString('sudo fleet upgrade --to v1.1.0', $err);
        self::assertSame(['start', 'installer'], array_column($this->history(), 'event'));
        self::assertSame('failed', $this->history()[1]['result']);
        foreach ($this->calls() as $call) {
            self::assertStringStartsNotWith('fleet upgrade:verify', $call);
        }
    }

    public function testAFailedVerificationExitsFiveWithTheRollbackCommand(): void
    {
        [$status, , $err] = $this->upgrade(['--yes'], ['FAKE_VERIFY_EXIT' => '5']);

        self::assertSame(5, $status);
        self::assertStringContainsString('To go back: sudo fleet upgrade --to v1.1.0', $err);
        self::assertSame(['start', 'installer', 'verify'], array_column($this->history(), 'event'));
        self::assertSame('unverified', $this->history()[2]['result']);
    }

    public function testTheTargetAndForceAreHandedToThePlan(): void
    {
        $this->upgrade(['--to', 'v1.2.1', '--force=disk.space', '--force=jobs.active', '--yes']);

        self::assertSame('fleet upgrade:prepare --to=v1.2.1 --force=disk.space --force=jobs.active', $this->calls()[0]);
    }

    public function testBadArgumentsAreAUsageErrorAndNothingRuns(): void
    {
        foreach ([['--bogus'], ['--to', 'v2.0.0'], ['--to=main'], ['--to=v1.2'], ['--force=BAD ID'], ['--to'], ['--check']] as $args) {
            [$status, , $err] = $this->upgrade([...$args, '--yes']);

            self::assertSame(2, $status, implode(' ', $args).': '.$err);
        }
        self::assertSame([], $this->calls());
    }

    public function testRefusesWhenTheHostNameCannotBeRead(): void
    {
        file_put_contents($this->root.'/etc/ddev-fleet/fleet.env', "APP_ENV=prod\n");

        [$status, , $err] = $this->upgrade(['--yes']);

        self::assertSame(3, $status);
        self::assertStringContainsString('cannot read the host name', $err);
        self::assertSame(['fleet upgrade:prepare'], $this->calls());
    }

    public function testRefusesToRunUnprivilegedWithoutTheTestRoot(): void
    {
        [$status, , $err] = $this->upgrade(['--yes'], ['FLEET_UPGRADE_TEST_ROOT' => false]);

        self::assertSame(3, $status);
        self::assertStringContainsString('must run as root', $err);
        self::assertSame([], $this->calls());
    }

    public function testTheReleaseSourceComesFromTheCommandLineNeverFromTheEnvironment(): void
    {
        $this->upgrade(['--yes'], ['FLEET_UPGRADE_REPO_URL' => '/nonexistent/evil.git']);

        self::assertStringNotContainsString('evil', (string) file_get_contents($this->log));
        $source = (string) file_get_contents(self::installDir().'/libexec/upgrade');
        $code = preg_replace('/^\s*#.*$/m', '', $source) ?? '';
        self::assertStringNotContainsString('FLEET_UPGRADE_REPO_URL', $code);
        self::assertStringContainsString('default_repo=https://github.com/rcuny/ddev-fleet.git', $code);
    }

    public function testThereIsNoSudoersEntryAndTheRootBranchIgnoresTheTestVariable(): void
    {
        $sudoers = (string) file_get_contents(self::installDir().'/etc/sudoers-fleet-apply');
        self::assertStringNotContainsString('upgrade', $sudoers, 'the application must not be able to start an upgrade');
        $step = (string) file_get_contents(self::installDir().'/steps/90-privileged.sh');
        self::assertStringContainsString('"$HELPER_DIR/upgrade" 0755 root:root', $step);
        self::assertCount(1, glob(self::installDir().'/etc/sudoers-*') ?: [], 'the apply sudoers file is the only one');
        self::assertStringNotContainsString('fleet-upgrade', $step);

        $source = (string) file_get_contents(self::installDir().'/libexec/upgrade');
        $before = substr($source, 0, (int) strpos($source, 'if [ "$EUID" -ne 0 ]; then'));
        self::assertStringNotContainsString('FLEET_UPGRADE_TEST_ROOT', preg_replace('/^\s*#.*$/m', '', $before) ?? '', 'the test variable is read only in the non-root branch');
        self::assertStringContainsString('PATH=$caller_path', $source);
    }

    // --- fixtures ----------------------------------------------------------------------------------------------------

    /** A local repository with one annotated tag v1.2.1 whose install/install.sh is a fake installer. */
    private function makeRepo(): void
    {
        $this->repo = $this->scratch().'/release.git-src';
        mkdir($this->repo.'/install', 0o755, true);
        file_put_contents($this->repo.'/install/install.sh', <<<'SH'
            #!/usr/bin/env bash
            echo "installer $*" >> "$FAKE_LOG"
            echo "fake installer of v1.2.1" > "$FAKE_RAN"
            exit "${FAKE_INSTALL_EXIT:-0}"
            SH);
        $env = ['GIT_AUTHOR_NAME' => 't', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 't', 'GIT_COMMITTER_EMAIL' => 't@example.com', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'];
        foreach ([['init', '-q', '-b', 'main'], ['add', '.'], ['commit', '-q', '-m', 'release'], ['tag', '-a', 'v1.2.1', '-m', 'v1.2.1']] as $args) {
            new Process(['git', '-C', $this->repo, ...$args], null, $env)->mustRun();
        }
        $this->sha = trim(new Process(['git', '-C', $this->repo, 'rev-parse', 'v1.2.1^{commit}'], null, $env)->mustRun()->getOutput());
    }

    /**
     * @param list<string>                $args
     * @param array<string, string|false> $env
     *
     * @return array{int, string, string}
     */
    private function upgrade(array $args, array $env = []): array
    {
        $fleet = <<<'SH'
            echo "fleet $*" >> "$FAKE_LOG"
            case "$1" in
              upgrade:prepare)
                printf 'STATE=%s\nFROM=v1.1.0\nTO=v1.2.1\nSHA=%s\nACTIONS=0\n' "${FAKE_PREPARE_STATE:-ready}" "${FAKE_SHA}"
                echo "plan report" >&2
                exit "${FAKE_PREPARE_EXIT:-0}" ;;
              upgrade:backup) [ "${FAKE_BACKUP_EXIT:-0}" = 0 ] || { echo "backup failed" >&2; exit 1; }; echo "BACKUP=fleet-20261104-100000.sqlite" ;;
              upgrade:verify) exit "${FAKE_VERIFY_EXIT:-0}" ;;
            esac
            SH;
        if (!is_dir($this->scratch().'/tmp')) {
            mkdir($this->scratch().'/tmp', 0o700, true);
        }
        $stubs = $this->stubs(['fleet' => $fleet]);
        $process = new Process(
            ['bash', self::installDir().'/libexec/upgrade', ...$args, '--repo-url='.$this->repo],
            null,
            $env + [
                'PATH' => $stubs.':'.(string) getenv('PATH'),
                'FLEET_UPGRADE_TEST_ROOT' => $this->root,
                'FAKE_LOG' => $this->log,
                'FAKE_RAN' => $this->scratch().'/installer-ran',
                'FAKE_SHA' => $this->sha,
                'TMPDIR' => $this->scratch().'/tmp',
                'GIT_CONFIG_GLOBAL' => '/dev/null',
                'GIT_CONFIG_SYSTEM' => '/dev/null',
            ],
            null,
            60,
        );
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /** @return list<string> the commands the stubs saw, in order */
    private function calls(): array
    {
        return array_values(array_filter(array_map(trim(...), file($this->log) ?: []), static fn (string $l): bool => '' !== $l));
    }

    private function historyFile(): string
    {
        return $this->root.'/var/log/ddev-fleet/upgrade-history.jsonl';
    }

    /** @return list<array<string, mixed>> */
    private function history(): array
    {
        $lines = is_file($this->historyFile()) ? file($this->historyFile(), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) : [];

        return array_map(static fn (string $l): array => json_decode($l, true, 4, \JSON_THROW_ON_ERROR), $lines ?: []);
    }
}
