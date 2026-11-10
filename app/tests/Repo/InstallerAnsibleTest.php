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
