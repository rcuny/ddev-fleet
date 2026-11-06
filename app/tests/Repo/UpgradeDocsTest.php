<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The upgrade documentation against the code (FLE-155): the options of the root helper, the pre-flight check ids of the
 * app and of the installer, the helper's exit codes, the history events, and the places that must point at the feature
 * (operate guide, release guide, CLAUDE.md, changelog fragment, rehearsal script). UpgradeCheckDocsTest covers the
 * `--check` options, statuses and JSON keys.
 */
final class UpgradeDocsTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $file = self::root().'/'.$relative;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    public function testEveryOptionOfTheRootHelperIsDocumented(): void
    {
        $helper = self::read('install/libexec/upgrade');
        preg_match_all('/^\s+--([a-z][a-z-]*)(?:=\*)?(?:\s*\||\))/m', $helper, $matches);
        $options = array_values(array_unique($matches[1]));
        self::assertContains('to', $options);
        self::assertContains('yes', $options);
        self::assertContains('force', $options);
        self::assertContains('no-db-restore', $options);
        self::assertContains('repo-url', $options);
        $doc = self::read('docs/reference/upgrade.md');
        foreach ($options as $option) {
            if ('help' === $option) {
                continue;
            }
            self::assertStringContainsString('`--'.$option, $doc, 'undocumented option --'.$option);
        }
    }

    public function testEveryAppCheckIdIsDocumented(): void
    {
        $ids = [];
        foreach (glob(self::root().'/app/src/Fleet/Upgrade/Check/*.php') ?: [] as $file) {
            preg_match_all("/public const string [A-Z_]+ = '([a-z]+(?:\\.[a-z-]+)+)';/", (string) file_get_contents($file), $found);
            array_push($ids, ...$found[1]);
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => !str_starts_with($id, 'installer.'))));
        self::assertGreaterThanOrEqual(15, \count($ids), 'the check ids were not found in the code');
        $doc = self::read('docs/reference/upgrade.md');
        foreach ($ids as $id) {
            self::assertStringContainsString('| `'.$id.'` |', $doc, 'undocumented pre-flight check '.$id);
        }
        foreach (['installer.unsupported', 'installer.unavailable', 'installer.failed'] as $id) {
            self::assertStringContainsString('`'.$id.'`', $doc);
        }
        self::assertStringContainsString("'check.crashed'", (string) file_get_contents(self::root().'/app/src/Fleet/Upgrade/Check/CheckRunner.php'));
    }

    public function testEveryInstallerPreflightIdIsDocumented(): void
    {
        $script = self::read('install/lib/preflight.sh');
        $ids = [];
        preg_match_all('/pf_emit (?:BLOCK|WARN|INFO) ([a-z][a-z.-]*[a-z])\b/', $script, $emit);
        preg_match_all('/pf_check_disk_one ([a-z]+\.[a-z]+)\b/', $script, $disk);
        preg_match_all('/^\s+pf_check_tool ([a-z]+\.[a-z]+)\b/m', $script, $tool);
        $ids = array_values(array_unique([...$emit[1], ...$disk[1], ...$tool[1]]));
        self::assertContains('host.os', $ids);
        self::assertContains('disk.var', $ids);
        self::assertContains('docker.answer', $ids);
        self::assertContains('network.repo', $ids);
        $doc = self::read('docs/reference/upgrade.md');
        foreach ($ids as $id) {
            self::assertStringContainsString('`'.$id.'`', $doc, 'undocumented installer pre-flight id '.$id);
        }
    }

    public function testTheHelperExitCodesAndHistoryEventsAreDocumented(): void
    {
        $doc = self::read('docs/reference/upgrade.md');
        foreach ([0, 1, 2, 3, 5] as $code) {
            self::assertStringContainsString('| '.$code.' |', $doc);
        }
        self::assertStringContainsString('Exit codes: 0 done and verified, 1 failed, 2 usage, 3 refused (nothing changed), 5 verification failed.', self::read('install/libexec/upgrade'));

        $history = self::read('app/src/Fleet/Upgrade/HistoryEntry.php').self::read('install/libexec/upgrade');
        foreach (['start', 'installer', 'restore', 'verify'] as $event) {
            self::assertStringContainsString('"'.$event.'"', $history.'"'.$event.'"');
            self::assertStringContainsString('| `'.$event.'` |', $doc, 'undocumented history event '.$event);
        }
        self::assertStringContainsString('/var/log/ddev-fleet/upgrade-history.jsonl', $doc);
    }

    public function testTheUpgradeHasNoSudoersEntry(): void
    {
        foreach (glob(self::root().'/install/etc/sudoers-*') ?: [] as $file) {
            self::assertStringNotContainsString('upgrade', (string) file_get_contents($file), basename($file).' must not grant the upgrade');
        }
        self::assertStringContainsString('the upgrade has no sudoers entry', self::read('CLAUDE.md'));
    }

    public function testTheGuidesPointAtFleetUpgrade(): void
    {
        $operate = self::read('docs/guides/operate.md');
        self::assertStringContainsString('reference/upgrade.md', $operate);
        self::assertStringContainsString('fleet upgrade --check', $operate);
        self::assertStringContainsString('sudo fleet upgrade', $operate);
        self::assertLessThan(strpos($operate, 'sudo fleet upgrade'), strpos($operate, 'fleet upgrade --check'), 'the read-only check comes before the upgrade');

        $installer = self::read('docs/reference/installer.md');
        self::assertStringContainsString('--ref=vX.Y.Z', $installer);

        $releasing = self::read('docs/contributing/releasing.md');
        self::assertStringContainsString('### Rollout notes and ACTION lines', $releasing);
        self::assertStringContainsString('ACTION:', $releasing);

        self::assertStringContainsString('upgrade.md', self::read('docs/README.md'));
        self::assertStringContainsString('docs/reference/upgrade.md', self::read('CLAUDE.md'));
    }

    public function testTheRehearsalScriptIsDocumentedAndSafe(): void
    {
        $path = self::root().'/ci/upgrade-rehearsal.sh';
        self::assertFileExists($path);
        self::assertTrue(is_executable($path), 'ci/upgrade-rehearsal.sh must be executable');
        $script = (string) file_get_contents($path);
        self::assertStringStartsWith('#!/bin/bash', $script);
        // It changes a host: it must demand the confirmation flag and root, and offer the harmless modes.
        foreach (['--yes-this-is-a-test-host', '--build-only', '--dry-run'] as $flag) {
            self::assertStringContainsString($flag, $script);
        }
        self::assertStringContainsString('run it as root', $script);

        $doc = self::read('docs/reference/upgrade.md');
        self::assertStringContainsString('## Rehearse on a test host', $doc);
        self::assertStringContainsString('ci/upgrade-rehearsal.sh', $doc);
        foreach (['--yes-this-is-a-test-host', '--build-only', '--dry-run'] as $flag) {
            self::assertStringContainsString($flag, $doc);
        }
    }

    public function testTheRehearsalScriptClonesAsTheCheckoutOwnerAndPointsOriginAtTheRepository(): void
    {
        $script = self::read('ci/upgrade-rehearsal.sh');
        // FLE-173: root cloning the fleet-owned checkout is refused by git (dubious ownership), so the clone runs as its owner.
        self::assertMatchesRegularExpression('/runuser -u "\$fleet_user" -- git clone/', $script);
        // The work clone, which root then commits in, is declared safe for the process tree, never in any git config.
        self::assertStringContainsString('GIT_CONFIG_VALUE_2=$work', $script);
        self::assertStringNotContainsString('git config --global', $script);
        self::assertStringNotContainsString('--system', $script);
        // checkout.origin must equal the release source for the run, and be put back afterwards.
        self::assertMatchesRegularExpression('/remote set-url origin "\$repo"/', $script);
        self::assertMatchesRegularExpression('/remote set-url origin "\$origin_before"/', $script);
    }

    public function testTheRehearsalPutsTheHostBackOnEveryExitPath(): void
    {
        // FLE-173: root, the tools and the source checkout are stubbed; the "upgrade" steps fail, so assertions fail and the
        // script exits non-zero. The env line and origin must be back as they were, and a forced signal must do the same.
        $dir = sys_get_temp_dir().'/fleet-rehearsal-test-'.bin2hex(random_bytes(4));
        mkdir($dir.'/bin', 0o755, true);
        mkdir($dir.'/etc', 0o755, true);
        try {
            $git = static function (string ...$args) use ($dir): string {
                $p = new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'protocol.file.allow=always', ...$args], $dir.'/src', ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null']);
                $p->mustRun();

                return $p->getOutput();
            };
            mkdir($dir.'/src');
            $git('init', '-q', '-b', 'main');
            file_put_contents($dir.'/src/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n");
            $git('add', 'CHANGELOG.md');
            $git('commit', '-q', '-m', 'base');
            $git('tag', '-a', 'v1.2.0', '-m', 'v1.2.0');
            $git('remote', 'add', 'origin', 'https://example.invalid/original.git');
            file_put_contents($dir.'/etc/installed-version', "v1.2.0\n");
            file_put_contents($dir.'/etc/fleet.env', "APP_ENV=prod\n");
            foreach (['id' => 'echo 0', 'chown' => 'exit 0', 'sudo' => 'shift 2; exec "$@"', 'runuser' => 'shift 3; exec "$@"', 'fleet' => 'if [ "$1" = upgrade ] && [ -n "${HANG:-}" ] && [ "$2" = --check ]; then kill -TERM $PPID; sleep 5; fi; [ "$1" = "upgrade:refresh" ] && exit 0; exit 1'] as $name => $body) {
                file_put_contents($dir.'/bin/'.$name, "#!/bin/bash\n".$body."\n");
                chmod($dir.'/bin/'.$name, 0o755);
            }
            foreach ([[], ['HANG' => '1']] as $extra) {
                file_put_contents($dir.'/etc/fleet.env', "APP_ENV=prod\n");
                $git('remote', 'set-url', 'origin', 'https://example.invalid/original.git');
                $run = new Process(
                    ['bash', self::root().'/ci/upgrade-rehearsal.sh', '--yes-this-is-a-test-host'],
                    $dir,
                    $extra + [
                        'PATH' => $dir.'/bin:'.(string) getenv('PATH'),
                        'FLEET_REHEARSAL_ETC_DIR' => $dir.'/etc',
                        'FLEET_REHEARSAL_SOURCE' => $dir.'/src',
                        'GIT_CONFIG_GLOBAL' => '/dev/null',
                        'GIT_CONFIG_SYSTEM' => '/dev/null',
                    ],
                    null,
                    120,
                );
                $run->run();
                self::assertNotSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
                self::assertSame("APP_ENV=prod\n", file_get_contents($dir.'/etc/fleet.env'), 'the override line is removed: '.$run->getOutput());
                self::assertSame('https://example.invalid/original.git', trim($git('remote', 'get-url', 'origin')), 'origin is what it was: '.$run->getOutput());
            }
        } finally {
            new Process(['rm', '-rf', '--', $dir])->run();
        }
    }

    public function testTheMilestoneEntryIsPresent(): void
    {
        // A changelog.d fragment on develop; once a release assembles the fragments (and deletes them) the entry lives in a
        // released version section of CHANGELOG.md.
        $fragmentFile = self::root().'/changelog.d/FLE-18.md';
        if (is_file($fragmentFile)) {
            $fragment = (string) file_get_contents($fragmentFile);
            self::assertStringStartsWith('### Added', $fragment);
            self::assertMatchesRegularExpression('/^## Rollout$/m', $fragment);
        } else {
            $changelog = self::read('CHANGELOG.md');
            self::assertGreaterThan(0, preg_match_all('/^## \[\d+\.\d+\.\d+\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $changelog, $sections), 'no released version section in CHANGELOG.md');
            $fragment = '';
            foreach ($sections[1] as $body) {
                // Word boundary: FLE-188 and friends must not match the FLE-18 milestone entry.
                if (1 === preg_match('/\bFLE-18\b/', $body)) {
                    $fragment = $body;
                    break;
                }
            }
            self::assertNotSame('', $fragment, 'neither changelog.d/FLE-18.md nor a released CHANGELOG.md section has the FLE-18 entry');
            self::assertStringContainsString('### Added', $fragment);
            self::assertMatchesRegularExpression('/^#### Rollout$/m', $fragment);
        }
        self::assertStringContainsString('fleet upgrade', $fragment);
    }
}
