<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * install/lib/preflight.sh through `run.sh --preflight`: read-only, no root, one BLOCK|WARN|INFO line per finding,
 * exit 0 or 3. External commands (ddev, docker, df, curl) are stubs in front of PATH.
 */
final class InstallerPreflightTest extends TestCase
{
    use InstallerHarness;

    /**
     * @param array<string, string> $stubs extra or overriding stub executables
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{int, string, string}
     */
    private function preflight(array $stubs = [], array $env = [], array $args = []): array
    {
        $stubs += [
            'df' => 'printf "Filesystem 1M-blocks Used Available Capacity Mounted\n/dev/x 100000 1000 99000 1%% /\n"',
            'curl' => 'exit 0',
            'docker' => 'exit 0',
            'ddev' => 'echo \'{"raw":[]}\'',
        ];
        $env += ['FLEET_OS_RELEASE' => $this->osRelease()];

        return $this->bash(
            [self::installDir().'/run.sh', '--preflight', '--domain=fleet.example.com', '--skip-dns-check', ...$args],
            $env,
            null,
            $this->stubs($stubs),
        );
    }

    private static function ddevList(string ...$ids): string
    {
        $rows = array_map(static fn (string $id): string => '{"name":"n","approot":"/srv/fleet/instances/'.$id.'"}', $ids);

        return 'echo '.escapeshellarg('{"raw":['.implode(',', $rows).']}');
    }

    public function testACleanHostPassesAndEveryLineIsAFinding(): void
    {
        [$status, $out, $err] = $this->preflight();

        self::assertSame(0, $status, $err.$out);
        foreach (explode("\n", trim($out)) as $line) {
            self::assertMatchesRegularExpression('/^(BLOCK|WARN|INFO) [a-z.-]+: .+/', $line);
        }
        self::assertStringNotContainsString('BLOCK', $out);
    }

    public function testASixtyCharacterInstanceIdIsABlock(): void
    {
        $long = str_repeat('a', 60);

        [$status, $out] = $this->preflight(['ddev' => self::ddevList($long, 'blog--main')]);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK instances.id-length: 1 instance id(s) longer than 59', $out);
        self::assertStringContainsString($long, $out);
    }

    public function testAnIdOfTheLimitPasses(): void
    {
        [$status, $out] = $this->preflight(['ddev' => self::ddevList(str_repeat('a', 59))]);

        self::assertSame(0, $status, $out);
        self::assertStringContainsString('INFO instances.id-length', $out);
    }

    public function testOtherDdevProjectsAreNotInstances(): void
    {
        $other = '{"raw":[{"name":"x","approot":"/home/someone/'.str_repeat('a', 80).'"}]}';

        [$status, $out] = $this->preflight(['ddev' => 'echo '.escapeshellarg($other)]);

        self::assertSame(0, $status, $out);
    }

    public function testLowDiskIsABlockNamingThePath(): void
    {
        $df = 'printf "Filesystem 1M-blocks Used Available Capacity Mounted\n/dev/x 100 90 10 90%% /\n"';

        [$status, $out] = $this->preflight(['df' => $df]);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK disk.opt: only 10 MiB free', $out);
        self::assertStringContainsString('BLOCK disk.srv:', $out);
        self::assertStringContainsString('BLOCK disk.var:', $out);
    }

    public function testADockerThatDoesNotAnswerIsABlock(): void
    {
        [$status, $out] = $this->preflight(['docker' => 'exit 1']);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK docker.answer:', $out);
    }

    public function testADdevThatDoesNotAnswerIsABlock(): void
    {
        [$status, $out] = $this->preflight(['ddev' => 'exit 1']);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK ddev.answer:', $out);
    }

    public function testGithubUnreachableIsABlockButAPathIsNotChecked(): void
    {
        [$status, $out] = $this->preflight(['curl' => 'exit 22']);
        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK network.repo:', $out);

        [$status, $out] = $this->preflight(['curl' => 'exit 22'], args: ['--repo-url=/srv/some/bundle']);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('INFO network.repo:', $out);
    }

    public function testAnUnsupportedOsIsABlock(): void
    {
        [$status, $out] = $this->preflight(env: ['FLEET_OS_RELEASE' => $this->osRelease('ubuntu', '24.04')]);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK host.os: this installer supports Debian 13 only', $out);
    }

    public function testRunsWithoutRootAndChangesNothing(): void
    {
        $before = shell_exec('ls -A /etc/ddev-fleet /srv/fleet 2>&1');

        [$status] = $this->preflight();

        self::assertSame(0, $status);
        self::assertSame($before, shell_exec('ls -A /etc/ddev-fleet /srv/fleet 2>&1'));
        self::assertFileDoesNotExist('/var/log/ddev-fleet-install.log.preflight');
    }

    public function testAnUnusableHubSecretBlocksAndMissingKeysDoNot(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The prefix harness is for unprivileged users.');
        }
        $prefix = $this->scratch().'/root';
        mkdir($prefix.'/etc/ddev-fleet', 0o700, true);
        $env = $prefix.'/etc/ddev-fleet/fleet.env';
        $run = fn (): array => $this->preflight(env: ['FLEET_ROOT_PREFIX' => $prefix]);

        file_put_contents($env, "APP_ENV=prod\n");
        [$status, $out] = $run();
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('INFO fleet.env: ', $out);
        self::assertStringContainsString('lacks', $out);

        file_put_contents($env, "APP_ENV=prod\nMERCURE_JWT_SECRET=short\n");
        [$status, $out] = $run();
        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK fleet.env: MERCURE_JWT_SECRET', $out);
    }

    public function testANormalRunChecksBeforeAnyStepAndStopsOnABlock(): void
    {
        $run = (string) file_get_contents(self::installDir().'/run.sh');

        $check = strpos($run, 'preflight_run || die "$EX_PREFLIGHT"');
        self::assertNotFalse($check);
        self::assertLessThan(strpos($run, 'steps/[0-9][0-9]-*.sh'), $check, 'the pre-flight comes before step 10');
    }

    public function testTheFlagIsMirroredInBothScriptsAndExitCode3IsDocumented(): void
    {
        foreach (['install.sh', 'lib/common.sh'] as $file) {
            $text = (string) file_get_contents(self::installDir().'/'.$file);
            self::assertStringContainsString('--preflight', $text, $file);
            self::assertStringContainsString('3 pre-flight blocked', $text, $file);
        }
        [$status, $out] = $this->bash([self::installDir().'/install.sh', '--preflight', '--domain=fleet.example.com'], ['FLEET_OS_RELEASE' => $this->osRelease()], null, $this->stubs(['df' => 'exit 0', 'curl' => 'exit 0']));
        self::assertContains($status, [0, 3], 'install.sh --preflight hands over to run.sh from a checkout and changes nothing');
        self::assertStringContainsString(' host.os:', $out);
    }
}
