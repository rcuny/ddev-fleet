<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Fleet\System\HostVarsRenderer;
use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * FLE-260: `apply provision`, run unprivileged against a scratch tree (FLEET_APPLY_TEST_ROOT) with an ansible-playbook
 * stub on PATH. The stub logs its arguments, so the tests assert the fixed command line and that nothing else runs.
 */
final class ApplyProvisionHelperTest extends TestCase
{
    use InstallerHarness;

    private string $root = '';
    private string $log = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The helper ignores FLEET_APPLY_TEST_ROOT as root, so these unprivileged scratch-tree tests cannot run as root.');
        }
        $this->root = $this->scratch().'/root';
        $this->log = $this->scratch().'/calls.log';
        foreach (['srv/fleet/staging/provision', 'etc/ddev-fleet', 'usr/local/lib/ddev-fleet-installer/install/ansible'] as $dir) {
            mkdir($this->root.'/'.$dir, 0o700, true);
        }
        touch($this->log);
        file_put_contents($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml', "---\n");
        chmod($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml', 0o644);
        foreach (['usr/local/lib/ddev-fleet-installer/install', 'usr/local/lib/ddev-fleet-installer/install/ansible'] as $dir) {
            chmod($this->root.'/'.$dir, 0o755);
        }
        file_put_contents($this->root.'/etc/ddev-fleet/installed-version', "v1.4.0@abc1234\n");
        file_put_contents($this->root.'/etc/ddev-fleet/host-vars.yml', new HostVarsRenderer()->render('fleet.example.com', null, true, false));
    }

    private function hardened(): string
    {
        return new HostVarsRenderer()->render('fleet.example.com', 'ops@example.com', true, true, ['admin'], ['203.0.113.10', '2001:db8::/32'], 15, true, 'admin');
    }

    private function stage(string $text): void
    {
        file_put_contents($this->root.'/srv/fleet/staging/provision/host-vars.yml', $text);
    }

    /**
     * @return array{int, string, string}
     */
    private function provision(int $playbookExit = 0): array
    {
        $stubs = $this->stubs(['ansible-playbook' => "echo \"ansible-playbook \$* (cwd \$PWD)\" >> {$this->log}\necho 'PLAY RECAP'\nexit {$playbookExit}"]);
        $process = new Process(['bash', self::installDir().'/libexec/apply', 'provision'], null, ['FLEET_APPLY_TEST_ROOT' => $this->root, 'PATH' => $stubs.':'.(string) getenv('PATH')], null, 60);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    private function calls(): string
    {
        return trim((string) file_get_contents($this->log));
    }

    public function testRunsTheInstalledPlaybookWithAFixedCommandLine(): void
    {
        [$status, $out] = $this->provision();

        self::assertSame(0, $status);
        self::assertStringContainsString('PLAY RECAP', $out);
        $call = $this->calls();
        self::assertStringContainsString('-i localhost, -c local '.$this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml', $call);
        // The ref comes from installed-version (without the @sha), strictness is on, and the run does not wait for its own job.
        self::assertStringContainsString('-e fleet_ref=v1.4.0 -e fleet_hardening_strict=true -e fleet_job_wait=0', $call);
        self::assertSame(1, substr_count($call, 'ansible-playbook'));
    }

    public function testInstallsTheStagedAnswersFirstAndRemovesTheStagedFile(): void
    {
        $this->stage($this->hardened());

        [$status, $out] = $this->provision();

        self::assertSame(0, $status, $out);
        self::assertSame($this->hardened(), file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml'));
        self::assertSame('0644', substr(\sprintf('%o', fileperms($this->root.'/etc/ddev-fleet/host-vars.yml')), -4));
        self::assertFileDoesNotExist($this->root.'/srv/fleet/staging/provision/host-vars.yml');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedAnswers(): iterable
    {
        $ok = "---\nfleet_domain: \"fleet.example.com\"\nfleet_hardening_enabled: false\n";
        yield 'unknown key' => [$ok."fleet_evil: true\n"];
        yield 'a key twice' => [$ok."fleet_domain: \"b.example.com\"\n"];
        yield 'shell injection in the domain' => ["---\nfleet_domain: \"x.example.com; id\"\nfleet_hardening_enabled: false\n"];
        yield 'jinja in a user' => ["---\nfleet_domain: \"x.example.com\"\nfleet_hardening_enabled: true\nfleet_hardening_ssh_allow_users:\n  - \"{{ lookup('pipe','id') }}\"\n"];
        yield 'a bad address' => ["---\nfleet_domain: \"x.example.com\"\nfleet_hardening_enabled: true\nfleet_hardening_trusted_ips:\n  - \"not an ip\"\n"];
        yield 'grace out of range' => [$ok."fleet_hardening_firewall_grace_minutes: 500\n"];
        yield 'a non-boolean' => ["---\nfleet_domain: \"x.example.com\"\nfleet_hardening_enabled: yes\n"];
        yield 'no domain' => ["---\nfleet_hardening_enabled: false\n"];
        yield 'an inline mapping' => [$ok."fleet_shell_profile: {a: b}\n"];
        yield 'no document start' => ["fleet_domain: \"x.example.com\"\nfleet_hardening_enabled: false\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAnswers')]
    public function testRefusesStagedAnswersThatBreakTheSchemaAndRunsNothing(string $text): void
    {
        $before = (string) file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml');
        $this->stage($text);

        [$status, , $err] = $this->provision();

        self::assertSame(65, $status, $err);
        self::assertSame('', $this->calls(), 'the playbook must not run');
        self::assertSame($before, file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml'));
        self::assertFileDoesNotExist($this->root.'/srv/fleet/staging/provision/host-vars.yml');
    }

    public function testAFailedRunPutsTheOldAnswersBackAndExitsNonZero(): void
    {
        $before = (string) file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml');
        $this->stage($this->hardened());

        [$status, , $err] = $this->provision(2);

        self::assertSame(1, $status);
        self::assertStringContainsString('provision failed (exit 2)', $err);
        self::assertSame($before, file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml'));
    }

    public function testRefusesAPlaybookTreeThatOthersCanWrite(): void
    {
        chmod($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible', 0o775);

        [$status, , $err] = $this->provision();

        self::assertSame(65, $status);
        self::assertStringContainsString('writable by group or others', $err);
        self::assertSame('', $this->calls());
    }

    public function testNeedsTheInstalledPlaybookAndVersion(): void
    {
        unlink($this->root.'/etc/ddev-fleet/installed-version');
        [$status, , $err] = $this->provision();
        self::assertSame(66, $status);
        self::assertStringContainsString('installed version', $err);

        unlink($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml');
        [$status, , $err] = $this->provision();
        self::assertSame(66, $status);
        self::assertStringContainsString('playbook is missing', $err);
    }

    public function testRefusesAnArgumentAfterTheTarget(): void
    {
        $process = new Process(['bash', self::installDir().'/libexec/apply', 'provision', '-e', 'x=1'], null, ['FLEET_APPLY_TEST_ROOT' => $this->root], null, 30);
        $process->run();

        self::assertSame(64, $process->getExitCode());
        self::assertSame('', $this->calls());
    }
}
