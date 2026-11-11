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

    private const DOCKER_VERSIONS = 'case "$1" in version) echo "%s";; compose) echo "%s";; *) exit 0;; esac';

    public function testRecentDockerComposeAndDdevPass(): void
    {
        [$status, $out, $err] = $this->preflight([
            'docker' => \sprintf(self::DOCKER_VERSIONS, '27.3.1', 'v2.29.7'),
            'ddev' => 'if [ "$1" = --version ]; then echo "ddev version v1.24.3"; else echo \'{"raw":[]}\'; fi',
        ]);

        self::assertSame(0, $status, $err.$out);
        self::assertStringContainsString('INFO docker.version: Docker Engine 27.3.1', $out);
        self::assertStringContainsString('INFO docker.compose-version: docker compose 2.29.7', $out);
        self::assertStringContainsString('INFO ddev.version: DDEV 1.24.3', $out);
    }

    public function testTheExactMinimumsPass(): void
    {
        [$status, $out] = $this->preflight([
            'docker' => \sprintf(self::DOCKER_VERSIONS, '26.0.0', '2.24.4'),
            'ddev' => 'if [ "$1" = --version ]; then echo "ddev version v1.24.0"; else echo \'{"raw":[]}\'; fi',
        ]);

        self::assertSame(0, $status, $out);
        self::assertStringNotContainsString('BLOCK', $out);
    }

    public function testAnOldDockerEngineIsABlock(): void
    {
        [$status, $out] = $this->preflight(['docker' => \sprintf(self::DOCKER_VERSIONS, '25.0.5', '2.29.7')]);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK docker.version: Docker Engine 25.0.5 is older than 26.0.0', $out);
        self::assertStringNotContainsString('BLOCK docker.compose-version', $out);
    }

    public function testAnOldComposeIsABlock(): void
    {
        [$status, $out] = $this->preflight(['docker' => \sprintf(self::DOCKER_VERSIONS, '27.0.0', '2.24.3')]);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK docker.compose-version: docker compose 2.24.3 is older than 2.24.4', $out);
    }

    public function testAnOldDdevIsABlock(): void
    {
        [$status, $out] = $this->preflight([
            'ddev' => 'if [ "$1" = --version ]; then echo "ddev version v1.23.5"; else echo \'{"raw":[]}\'; fi',
        ]);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK ddev.version: DDEV 1.23.5 is older than 1.24.0', $out);
    }

    public function testAVersionThatCannotBeReadIsNotABlock(): void
    {
        [$status, $out] = $this->preflight();

        self::assertSame(0, $status, $out);
        self::assertStringContainsString('INFO docker.version: cannot read the Docker Engine version', $out);
    }

    public function testADdevThatDoesNotAnswerIsABlock(): void
    {
        [$status, $out] = $this->preflight(['ddev' => 'exit 1']);

        self::assertSame(3, $status);
        self::assertStringContainsString('BLOCK ddev.answer:', $out);
    }

    private const ROOT_REFUSING_DDEV = 'if [ "${DDEV_AS:-}" != fleet ]; then echo "DDEV is not designed to be run with root privileges" >&2; exit 1; fi; echo \'{"raw":[]}\'';
    private const RUNUSER_STUB = 'export DDEV_AS=fleet; shift 3; exec "$@"';

    public function testAsRootDdevRunsAsTheFleetUserAndDoesNotBlock(): void
    {
        [$status, $out, $err] = $this->preflight(
            ['ddev' => self::ROOT_REFUSING_DDEV, 'runuser' => self::RUNUSER_STUB, 'getent' => 'exit 0'],
            ['PREFLIGHT_EUID' => '0'],
        );

        self::assertSame(0, $status, $err.$out);
        self::assertStringContainsString('INFO ddev.answer: ddev answers', $out);
        self::assertStringNotContainsString('BLOCK', $out);
    }

    public function testAsRootWithoutTheFleetUserDdevIsNotCheckedNorBlocked(): void
    {
        [$status, $out, $err] = $this->preflight(
            ['ddev' => self::ROOT_REFUSING_DDEV, 'getent' => 'exit 2'],
            ['PREFLIGHT_EUID' => '0'],
        );

        self::assertSame(0, $status, $err.$out);
        self::assertStringContainsString('INFO ddev.answer:', $out);
        self::assertStringContainsString('not checked', $out);
    }

    public function testAsRootADdevThatFailsAsTheFleetUserIsStillABlock(): void
    {
        [$status, $out] = $this->preflight(
            ['ddev' => 'exit 1', 'runuser' => self::RUNUSER_STUB, 'getent' => 'exit 0'],
            ['PREFLIGHT_EUID' => '0'],
        );

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

    public function testANormalRunChecksBeforeTheEngineAndStopsOnABlock(): void
    {
        $run = (string) file_get_contents(self::installDir().'/run.sh');

        $check = strpos($run, 'preflight_run || die "$EX_PREFLIGHT"');
        self::assertNotFalse($check);
        self::assertLessThan(strpos($run, 'ansible_engine_main'), $check, 'the pre-flight comes before the engine');
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
