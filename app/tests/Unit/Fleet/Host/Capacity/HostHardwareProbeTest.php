<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Host\Capacity;

use App\Fleet\Host\Capacity\HostHardwareProbe;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use PHPUnit\Framework\TestCase;

/** FLE-212: the thread count is the host's (`nproc --all`), not the affinity-bound `nproc`. */
final class HostHardwareProbeTest extends TestCase
{
    public function testAsksForAllCpusAndSurvivesARestrictedNproc(): void
    {
        $runner = new class implements CommandRunner {
            /** @var list<list<string>> */
            public array $argvs = [];

            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                $this->argvs[] = $argv;
                if (['nproc', '--all'] === $argv) {
                    return new CommandResult(0, "48\n");
                }
                if (['nproc'] === $argv) {
                    return new CommandResult(0, "4\n"); // the affinity-bound answer of a restricted unit
                }

                return new CommandResult(0, "Core(s) per socket: 12\nSocket(s): 2\n");
            }
        };
        $dir = sys_get_temp_dir();
        $hardware = new HostHardwareProbe($runner, $dir.'/no-meminfo', $dir.'/no-cpuinfo')->read();

        self::assertSame(48, $hardware->threads);
        self::assertSame(24, $hardware->cores, '2 sockets x 12 cores');
        self::assertContains(['nproc', '--all'], $runner->argvs);
        self::assertNotContains(['nproc'], $runner->argvs);
    }

    public function testFallsBackToCpuinfoWhenNprocFails(): void
    {
        $runner = new class implements CommandRunner {
            public function run(array $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult
            {
                return new CommandResult(127);
            }
        };
        $file = tempnam(sys_get_temp_dir(), 'cpuinfo');
        self::assertIsString($file);
        file_put_contents($file, str_repeat("processor\t: 0\n\n", 12));
        $hardware = new HostHardwareProbe($runner, '/nonexistent', $file)->read();
        unlink($file);

        self::assertSame(12, $hardware->threads);
        self::assertSame(12, $hardware->cores);
    }
}
