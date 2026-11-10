<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * FLE-241 (spike) and FLE-255: the Ansible port in install/ansible/ is not run by the installer. This pins the one piece that failed
 * on a real host: parsing a hardening.conf with comment lines. Skips without ansible-playbook.
 */
final class InstallerAnsibleTest extends TestCase
{
    public function testHardeningConfFixtureParses(): void
    {
        $bin = new ExecutableFinder()->find('ansible-playbook');
        if (null === $bin) {
            self::markTestSkipped('ansible-playbook is not installed');
        }
        $dir = \dirname(__DIR__, 3).'/install/ansible';
        $process = new Process([$bin, 'tests/parse-hardening-conf.yml'], $dir, ['ANSIBLE_LOCALHOST_WARNING' => 'False', 'HOME' => sys_get_temp_dir()]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testSiteRunsTheProvisioningRolesInOrderAfterDocker(): void
    {
        $site = (string) file_get_contents(\dirname(__DIR__, 3).'/install/ansible/site.yml');
        preg_match_all('/^    - role: (\w+)$/m', $site, $m);

        self::assertSame(['packages', 'docker', 'fleet_user', 'shell', 'ddev', 'hardening'], $m[1]);
    }

    public function testShellRoleWritesTheSameHookBlockAsTheBashStep(): void
    {
        $root = \dirname(__DIR__, 3).'/install';
        $bash = (string) file_get_contents($root.'/steps/35-shell.sh');
        $role = (string) file_get_contents($root.'/ansible/roles/shell/tasks/main.yml');

        self::assertStringContainsString("SHELL_HOOK_BEGIN='# BEGIN ddev-fleet shell profile'", $bash);
        self::assertStringContainsString("SHELL_HOOK_END='# END ddev-fleet shell profile'", $bash);
        self::assertStringContainsString('marker: "# {mark} ddev-fleet shell profile"', $role);
        self::assertStringContainsString('marker_begin: BEGIN', $role);
        self::assertStringContainsString('marker_end: END', $role);
        self::assertStringContainsString('if [ -f {{ fleet_shell_profile_file }} ]; then', $role);
        self::assertStringContainsString('  . {{ fleet_shell_profile_file }}', $role);
    }

    public function testSshRescueNeverDeletesADropInThatWasNotReplaced(): void
    {
        $ssh = (string) file_get_contents(\dirname(__DIR__, 3).'/install/ansible/roles/hardening/tasks/ssh.yml');

        self::assertStringNotContainsString('backup: true', $ssh);
        self::assertStringContainsString('hardening_ssh_dropin is changed', $ssh);
        self::assertStringContainsString('ssh-ports.sh', $ssh);
    }

    public function testATagLimitedRunOfAnotherRoleReachesNoHardeningTask(): void
    {
        $bin = new ExecutableFinder()->find('ansible-playbook');
        if (null === $bin) {
            self::markTestSkipped('ansible-playbook is not installed');
        }
        $dir = \dirname(__DIR__, 3).'/install/ansible';
        $env = ['ANSIBLE_LOCALHOST_WARNING' => 'False', 'HOME' => sys_get_temp_dir()];
        foreach (['ddev', 'docker', 'shell'] as $tag) {
            $process = new Process([$bin, 'site.yml', '--list-tasks', '--tags', $tag], $dir, $env);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            self::assertStringNotContainsString('hardening :', $process->getOutput(), '--tags '.$tag);
        }
        $process = new Process([$bin, 'site.yml', '--list-tasks', '--tags', 'hardening'], $dir, $env);
        $process->run();
        self::assertStringContainsString('hardening : Validate the settings', $process->getOutput());
    }

    public function testNoHardeningTaskIsTaggedAlways(): void
    {
        $files = glob(\dirname(__DIR__, 3).'/install/ansible/roles/hardening/{tasks,handlers}/*.yml', \GLOB_BRACE);
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringNotContainsString('always]', (string) file_get_contents($file), basename($file));
        }
    }
}
