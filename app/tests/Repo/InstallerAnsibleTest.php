<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * FLE-241 (spike): the Ansible port in install/ansible/ is not run by the installer. This pins the one piece that failed
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
}
