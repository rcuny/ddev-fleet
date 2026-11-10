<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * FLE-261: `apply drift` (read-only twin of `provision`), run unprivileged against a scratch tree with an
 * ansible-playbook stub, and the fleet_drift callback plugin against a real ansible-playbook when one is installed.
 */
final class ApplyDriftHelperTest extends TestCase
{
    use InstallerHarness;

    private string $root = '';
    private string $log = '';

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The helper ignores FLEET_APPLY_TEST_ROOT as root.');
        }
        $this->root = $this->scratch().'/root';
        $this->log = $this->scratch().'/calls.log';
        foreach (['srv/fleet/staging/drift', 'etc/ddev-fleet', 'usr/local/lib/ddev-fleet-installer/install/ansible'] as $dir) {
            mkdir($this->root.'/'.$dir, 0o700, true);
        }
        touch($this->log);
        file_put_contents($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml', "---\n");
        chmod($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml', 0o644);
        foreach (['usr/local/lib/ddev-fleet-installer/install', 'usr/local/lib/ddev-fleet-installer/install/ansible'] as $dir) {
            chmod($this->root.'/'.$dir, 0o755);
        }
        file_put_contents($this->root.'/etc/ddev-fleet/installed-version', "v1.7.0@abc1234\n");
        file_put_contents($this->root.'/etc/ddev-fleet/host-vars.yml', "---\nfleet_domain: \"fleet.example.com\"\nfleet_hardening_enabled: false\n");
    }

    /**
     * @return array{int, string, string}
     */
    private function drift(int $playbookExit = 0): array
    {
        $stubs = $this->stubs(['ansible-playbook' => "echo \"ansible-playbook \$* cb=\$ANSIBLE_STDOUT_CALLBACK\" >> {$this->log}\necho 'FLEET-DRIFT-REPORT-BEGIN'\nexit {$playbookExit}"]);
        $process = new Process(['bash', self::installDir().'/libexec/apply', 'drift'], null, ['FLEET_APPLY_TEST_ROOT' => $this->root, 'PATH' => $stubs.':'.(string) getenv('PATH')], null, 60);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    public function testRunsTheInstalledPlaybookInCheckModeWithTheDriftCallback(): void
    {
        [$status, $out, $err] = $this->drift();

        self::assertSame(0, $status, $err);
        // stdout is the playbook's alone: the report is parsed from it.
        self::assertSame("FLEET-DRIFT-REPORT-BEGIN\n", $out);
        self::assertStringContainsString('drift check of v1.7.0', $err);
        $call = trim((string) file_get_contents($this->log));
        self::assertStringContainsString('-i localhost, -c local '.$this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml --check --diff', $call);
        self::assertStringContainsString('-e fleet_ref=v1.7.0 -e fleet_hardening_strict=true -e fleet_job_wait=0 cb=fleet_drift', $call);
        self::assertSame(1, substr_count($call, 'ansible-playbook'));
    }

    public function testNeverWritesTheHostVars(): void
    {
        $before = (string) file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml');
        file_put_contents($this->root.'/srv/fleet/staging/drift/host-vars.yml', "fleet_domain: \"evil.example.com\"\n");

        $this->drift();

        self::assertSame($before, file_get_contents($this->root.'/etc/ddev-fleet/host-vars.yml'));
    }

    public function testPlaybookExitCodeIsTheHelpers(): void
    {
        [$status, , $err] = $this->drift(2);

        self::assertSame(2, $status);
        self::assertStringContainsString('drift check ended with exit 2', $err);
    }

    public function testRefusesWithoutAnInstalledPlaybook(): void
    {
        unlink($this->root.'/usr/local/lib/ddev-fleet-installer/install/ansible/site.yml');

        [$status, , $err] = $this->drift();

        self::assertSame(66, $status);
        self::assertStringContainsString('installed playbook is missing', $err);
    }

    public function testCallbackPrintsOneReportWithChangedDriftAndFailedTasks(): void
    {
        $bin = new ExecutableFinder()->find('ansible-playbook');
        if (null === $bin) {
            self::markTestSkipped('ansible-playbook is not installed');
        }
        $ansible = \dirname(__DIR__, 3).'/install/ansible';
        $dir = $this->scratch().'/play';
        mkdir($dir.'/roles/r1/tasks', 0o700, true);
        file_put_contents($dir.'/roles/r1/tasks/main.yml', <<<YAML
            - name: Write a file
              ansible.builtin.copy:
                content: "token: sekrit\\n"
                dest: {$dir}/target
            - name: Say drift
              ansible.builtin.debug:
                msg: "DRIFT: thing would be refreshed"
              when: ansible_check_mode
            - name: Hidden
              ansible.builtin.copy:
                content: "x\\n"
                dest: {$dir}/hidden
              no_log: true
            - name: Boom
              ansible.builtin.fail:
                msg: nope
            YAML);
        file_put_contents($dir.'/site.yml', "- hosts: all\n  gather_facts: false\n  roles: [r1]\n");
        mkdir($dir.'/callback_plugins');
        copy($ansible.'/callback_plugins/fleet_drift.py', $dir.'/callback_plugins/fleet_drift.py');
        $process = new Process([$bin, '-i', 'localhost,', '-c', 'local', 'site.yml', '--check', '--diff'], $dir, ['ANSIBLE_STDOUT_CALLBACK' => 'fleet_drift', 'ANSIBLE_LOCALHOST_WARNING' => 'False', 'HOME' => $this->scratch(), 'PATH' => (string) getenv('PATH')]);
        $process->run();

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        $out = $process->getOutput();
        if (1 !== preg_match('/^FLEET-DRIFT-REPORT-BEGIN\n(.+)\nFLEET-DRIFT-REPORT-END$/ms', $out, $m)) {
            self::fail($out);
        }
        $report = json_decode($m[1], true, 16, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['schema']);
        $role = $report['roles'][0];
        self::assertSame('r1', $role['role']);
        self::assertSame(['Write a file', 'Hidden'], array_column($role['changed'], 'task'));
        self::assertSame('(hidden: the task has no_log)', $role['changed'][1]['diff']);
        self::assertSame(['thing would be refreshed'], $role['drift']);
        self::assertSame('Boom', $role['failed'][0]['task']);
        self::assertFileDoesNotExist($dir.'/target');
    }
}
