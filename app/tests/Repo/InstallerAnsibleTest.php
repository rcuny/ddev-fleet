<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * FLE-241 (spike), FLE-256: the Ansible port in install/ansible/ is not run by the installer. This pins the one piece that failed
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

    /**
     * FLE-257: the app-layer roles install files from the root-owned export of install/. A unit renamed or removed
     * there must fail here, not on a host.
     */
    public function testAppLayerRolesReferenceExistingInstallFiles(): void
    {
        $root = \dirname(__DIR__, 3).'/install';
        $defaults = file_get_contents($root.'/ansible/roles/units/defaults/main.yml');
        self::assertIsString($defaults);
        preg_match_all('/\bfleet-[a-z-]+(?:@)?\.(?:service|timer)\b/', $defaults, $matches);
        self::assertNotEmpty($matches[0]);
        foreach (array_unique($matches[0]) as $unit) {
            self::assertFileExists($root.'/etc/'.$unit, $unit.' is listed in the units role');
        }

        $sources = '';
        foreach (['php_app', 'privileged', 'units'] as $role) {
            foreach (glob($root.'/ansible/roles/'.$role.'/tasks/*.yml') ?: [] as $file) {
                $sources .= file_get_contents($file);
            }
        }
        preg_match_all('#fleet_install_dir \}\}/(etc|libexec|bin)/([A-Za-z0-9@._-]+)#', $sources, $files, \PREG_SET_ORDER);
        self::assertNotEmpty($files);
        foreach ($files as [, $dir, $name]) {
            self::assertFileExists($root.'/'.$dir.'/'.$name);
        }
    }

    public function testSiteRunsTheAppLayerRolesInTheOrderOfTheBashSteps(): void
    {
        $site = file_get_contents(\dirname(__DIR__, 3).'/install/ansible/site.yml');
        self::assertIsString($site);
        preg_match_all('/- role: (\w+)/', $site, $roles);
        $order = array_values(array_intersect($roles[1], ['php_app', 'app_code', 'privileged', 'units']));
        self::assertSame(['php_app', 'app_code', 'privileged', 'units'], $order);
    }

    public function testCaddyMinimumVersionMatchesTheBashInstaller(): void
    {
        $root = \dirname(__DIR__, 3).'/install';

        self::assertSame(
            self::match('/^CADDY_MIN_VERSION=(\S+)$/m', $root.'/lib/common.sh'),
            self::match('/^fleet_caddy_min_version: (\S+)$/m', $root.'/ansible/group_vars/all.yml'),
        );
    }

    private static function match(string $pattern, string $file): string
    {
        $found = preg_match($pattern, (string) file_get_contents($file), $m);
        self::assertSame(1, $found, $file);
        self::assertArrayHasKey(1, $m);

        return $m[1];
    }
}
