<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Fleet\System\ConfigValue;
use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The installer's command line: flags, exit codes, the host checks and the plan a dry-run prints.
 */
final class InstallerEntryTest extends TestCase
{
    use InstallerHarness;

    public function testHelpExits0(): void
    {
        [$status, $out] = $this->bash([self::installDir().'/install.sh', '--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('--domain=<fqdn>', $out);
        self::assertStringContainsString('--dry-run', $out);
    }

    public function testRunScriptHelpExits0TooAndMentionsNoOwnerHost(): void
    {
        [$status, $out] = $this->bash([self::installDir().'/run.sh', '--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('fleet.example.com', $out);
    }

    public function testMissingDomainExits64AndTouchesNothing(): void
    {
        [$status, $out, $err] = $this->bash([self::installDir().'/install.sh', '--dry-run']);

        self::assertSame(64, $status);
        self::assertSame('', $out, 'the plan must not be printed before the flags are valid');
        self::assertStringContainsString('--domain', $err);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDomains(): iterable
    {
        foreach (['FOO.example.com', 'a_b.example.com', '*.example.com', '203.0.113.7', 'localhost', 'example', '-a.example.com', 'a-.example.com',
            'a..example.com', 'example.c', 'example.123', 'fleet.example.com.', 'fleet example.com', 'fleet.example.com:443',
            'fleet.example.com/x', 'fleet.example.com;id', str_repeat('a', 64).'.example.com'] as $domain) {
            yield $domain => [$domain];
        }
    }

    #[DataProvider('invalidDomains')]
    public function testInvalidDomainsExit64(string $domain): void
    {
        [$status, $out] = $this->dryRun(['--domain='.$domain, '--skip-dns-check']);

        self::assertSame(64, $status);
        self::assertSame('', $out);
    }

    public function testEmptyDomainAndNewlineDomainExit64(): void
    {
        self::assertSame(64, $this->dryRun(['--domain=', '--skip-dns-check'])[0]);
        self::assertSame(64, $this->dryRun(["--domain=fleet.example.com\nx", '--skip-dns-check'])[0]);
    }

    /**
     * The bash validators must accept and refuse exactly what the app's ConfigValue does, in both scripts.
     *
     * @return iterable<string, array{string}>
     */
    public static function domainCandidates(): iterable
    {
        foreach (['fleet.example.com', 'a.bc', 'sub.fleet.example.com', 'a-b.example.org', 'xn--bcher-kva.example.com', 'fleet.example.com.',
            'Fleet.example.com', 'example', 'ab.12', 'a--b.example.com', '1.example.com', 'example.c0m', str_repeat('a', 63).'.example.com',
            str_repeat('a', 64).'.example.com', 'fleet.example.com ', ' fleet.example.com', "fleet.example.com\n", 'a.b.c.d.e.f.example.com'] as $domain) {
            yield addcslashes($domain, "\n ") => [$domain];
        }
    }

    #[DataProvider('domainCandidates')]
    public function testDomainValidationMatchesTheAppAndBothScripts(string $domain): void
    {
        try {
            ConfigValue::domain($domain);
            $app = true;
        } catch (\InvalidArgumentException) {
            $app = false;
        }

        $common = new Process(['bash', '-c', 'set -Eeuo pipefail; . '.escapeshellarg(self::installDir().'/lib/common.sh').'; validate_domain "$1"', '_', $domain]);
        $common->run();
        $installSh = $this->dryRun(['--domain='.$domain, '--skip-dns-check']);

        self::assertSame($app, 0 === $common->getExitCode(), 'common.sh validate_domain disagrees with ConfigValue for '.json_encode($domain));
        self::assertSame($app, 0 === $installSh[0], 'install.sh disagrees with ConfigValue for '.json_encode($domain).' (exit '.$installSh[0].')');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emailCandidates(): iterable
    {
        foreach (['ops@example.com', 'a.b+c@sub.example.org', 'nobody', 'a@b', 'a b@example.com', "a@example.com\n", '@example.com', 'a@example.c'] as $email) {
            yield addcslashes($email, "\n ") => [$email];
        }
    }

    #[DataProvider('emailCandidates')]
    public function testAcmeEmailValidationMatchesTheApp(string $email): void
    {
        try {
            ConfigValue::acmeEmail($email);
            $app = true;
        } catch (\InvalidArgumentException) {
            $app = false;
        }
        $installSh = $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--acme-email='.$email]);

        self::assertSame($app, 0 === $installSh[0], 'install.sh disagrees with ConfigValue for '.json_encode($email));
    }

    public function testRefAndRepoUrlCannotBeOptionsOrContainWhitespace(): void
    {
        foreach (['--ref=-x', '--ref=a..b', '--ref=a b', '--ref=', '--repo-url=-x', '--repo-url=a b', '--repo-url='] as $flag) {
            self::assertSame(64, $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', $flag])[0], $flag);
        }
        self::assertSame(0, $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--ref=feature/FLE-1-x', '--repo-url=/tmp/fleet.bundle'])[0]);
    }

    public function testUnknownFlagExits64(): void
    {
        [$status, , $err] = $this->dryRun(['--domain=fleet.example.com', '--frobnicate']);

        self::assertSame(64, $status);
        self::assertStringContainsString('--frobnicate', $err);
    }

    public function testNotRootExits77UnlessDryRun(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The installer is root-only; this test needs an unprivileged user (the Bitbucket image runs as root).');
        }

        [$status, $out, $err] = $this->bash([self::installDir().'/install.sh', '--domain=fleet.example.com', '--skip-dns-check']);
        self::assertSame(77, $status);
        self::assertSame('', $out, 'nothing may be planned or done as a normal user');
        self::assertStringContainsString('root', $err);

        [$runStatus] = $this->bash([self::installDir().'/run.sh', '--domain=fleet.example.com', '--skip-dns-check']);
        self::assertSame(77, $runStatus);

        self::assertSame(0, $this->dryRun()[0], 'a dry-run works without root');
    }

    public function testNonDebian13Exits78UnlessForced(): void
    {
        foreach ([['ubuntu', '24.04'], ['debian', '12'], ['debian', '14']] as [$id, $version]) {
            $env = ['FLEET_OS_RELEASE' => $this->osRelease($id, $version)];
            [$status, $out, $err] = $this->bash([self::installDir().'/install.sh', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'], $env);
            self::assertSame(78, $status, $id.' '.$version);
            self::assertSame('', $out);
            self::assertStringContainsString('Debian 13', $err);

            [$status] = $this->bash([self::installDir().'/run.sh', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'], $env);
            self::assertSame(78, $status, 'run.sh refuses too');

            [$forced, , $warning] = $this->bash([self::installDir().'/install.sh', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'], $env + ['FLEET_FORCE_OS' => '1']);
            self::assertSame(0, $forced);
            self::assertStringContainsString('FLEET_FORCE_OS=1', $warning);
        }
    }

    public function testUnsupportedArchitectureExits78(): void
    {
        $stubs = $this->stubs(['uname' => 'echo riscv64']);

        [$status, , $err] = $this->dryRun(null, [], $stubs);

        self::assertSame(78, $status);
        self::assertStringContainsString('architecture', $err);
    }

    public function testOsFixtureIsIgnoredOutsideDryRun(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('Would run the real installer as root.');
        }
        // Not a dry-run and not root: refused before any os-release is read; the fixture cannot unlock anything.
        [$status] = $this->bash([self::installDir().'/install.sh', '--domain=fleet.example.com'], ['FLEET_OS_RELEASE' => $this->osRelease()]);

        self::assertSame(77, $status);
    }

    public function testDryRunPrintsEveryStepInOrder(): void
    {
        [$status, $out] = $this->dryRun();

        self::assertSame(0, $status);
        preg_match_all('/^==> (\S+)$/m', $out, $matches);
        $steps = self::stepNames();
        self::assertNotSame([], $steps);
        self::assertSame($steps, $matches[1], 'the dry-run plan must list exactly the steps of install/steps/, in numeric order');
        self::assertStringContainsString('dry-run complete', $out);
        self::assertStringContainsString('[stage 1]', $out);
    }

    public function testDryRunFromRunScriptAlonePrintsTheSameStepLines(): void
    {
        [, $viaInstall] = $this->dryRun();
        [$status, $viaRun] = $this->bash(
            [self::installDir().'/run.sh', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'],
            ['FLEET_OS_RELEASE' => $this->osRelease()],
        );

        self::assertSame(0, $status);
        self::assertSame(
            (string) preg_replace('/\A.*?(?=^dry-run: nothing)/ms', '', $viaInstall),
            $viaRun,
            'install.sh hands over to run.sh with no extra output of its own after stage 1',
        );
    }

    public function testDryRunChangesNothing(): void
    {
        $before = $this->snapshot();
        $this->dryRun();
        $this->dryRun(['--domain=fleet.example.com', '--skip-dns-check', '--acme-email=ops@example.com']);

        self::assertSame($before, $this->snapshot());
    }

    public function testPipedScriptWorksLikeAFile(): void
    {
        $script = (string) file_get_contents(self::installDir().'/install.sh');

        [$status, $out] = $this->bash(['-s', '--', '--help'], [], $script);
        self::assertSame(0, $status);
        self::assertStringContainsString('Usage: install.sh', $out);

        [$status, , $err] = $this->bash(['-s', '--', '--dry-run'], [], $script);
        self::assertSame(64, $status);
        self::assertStringContainsString('--domain', $err);

        [$status, $out] = $this->bash(['-s', '--', '--dry-run', '--domain=fleet.example.com', '--skip-dns-check'], ['FLEET_OS_RELEASE' => $this->osRelease()], $script);
        self::assertSame(0, $status);
        self::assertStringContainsString('+ exec bash', $out, 'piped, there is no checkout to take the step plan from');
    }

    public function testTruncatedDownloadRunsNothing(): void
    {
        $script = (string) file_get_contents(self::installDir().'/install.sh');
        // Cut inside main(): the function is never completed, so bash stops at a syntax error before running anything.
        $truncated = substr($script, 0, (int) (\strlen($script) * 0.6));

        [$status, $out] = $this->bash(['-s', '--', '--dry-run', '--domain=fleet.example.com'], ['FLEET_OS_RELEASE' => $this->osRelease()], $truncated);

        self::assertNotSame(0, $status);
        self::assertSame('', $out);
    }

    public function testEverythingRunsInsideMain(): void
    {
        $lines = file(self::installDir().'/install.sh', \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame('main "$@"', end($lines), 'the last line must be the only call');
        $topLevel = array_filter($lines, static fn (string $l): bool => 1 === preg_match('/^[a-z_]+\b/', $l) && !str_starts_with($l, 'main()'));
        self::assertSame(['main "$@"'], array_values($topLevel), 'no statement may run outside main()');
    }

    public function testAllScriptsPassBashSyntaxCheck(): void
    {
        $scripts = self::shellScripts();
        self::assertGreaterThanOrEqual(9, \count($scripts));
        foreach ($scripts as $script) {
            $process = new Process(['bash', '-n', $script]);
            self::assertSame(0, $process->run(), $script.': '.$process->getErrorOutput());
        }
    }

    public function testEntryScriptsAreExecutable(): void
    {
        foreach (['install.sh', 'run.sh'] as $name) {
            self::assertTrue(is_executable(self::installDir().'/'.$name), $name.' must be executable');
        }
    }

    public function testShellcheckCleanWhenAvailable(): void
    {
        if (0 !== new Process(['shellcheck', '--version'])->run()) {
            self::markTestSkipped('shellcheck is not installed (apt-get install shellcheck).');
        }

        $process = new Process(['shellcheck', '-x', ...self::shellScripts()], self::installDir());
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    /** @return array<string, string> a few facts a dry-run must not change */
    private function snapshot(): array
    {
        $paths = ['/opt/ddev-fleet', '/srv/fleet', '/etc/ddev-fleet', '/etc/caddy', '/etc/authelia', '/etc/apt/sources.list.d'];
        $state = [];
        foreach ($paths as $path) {
            $state[$path] = file_exists($path) ? (string) filemtime($path) : 'absent';
        }

        return $state;
    }
}
