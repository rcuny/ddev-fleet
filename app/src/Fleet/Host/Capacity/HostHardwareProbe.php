<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use App\Fleet\Shell\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the hardware: `nproc --all` (every CPU of the host, not the affinity-bound count a restricted shell or a cpuset-limited unit sees; FLE-212) and `lscpu` through the CommandRunner seam, /proc/meminfo directly. A value that cannot be
 * read falls back (threads: the `processor` lines of /proc/cpuinfo, then 1; cores: the thread count; RAM: 0) so the
 * capacity report never fails on a sandbox without the tools.
 */
final readonly class HostHardwareProbe
{
    public function __construct(
        private CommandRunner $runner,
        #[Autowire('%fleet.meminfo_file%')]
        private string $meminfoFile = '/proc/meminfo',
        #[Autowire('%fleet.cpuinfo_file%')]
        private string $cpuinfoFile = '/proc/cpuinfo',
    ) {
    }

    public function read(): HostHardware
    {
        $threads = HostHardware::parseThreads($this->output(['nproc', '--all'])) ?? $this->cpuinfoThreads() ?? 1;
        $cores = HostHardware::parseCores($this->output(['lscpu'])) ?? $threads;
        $ram = HostHardware::parseRamMb((string) @file_get_contents($this->meminfoFile)) ?? 0;

        return new HostHardware($threads, min($cores, $threads), $ram);
    }

    /**
     * @param list<string> $argv
     */
    private function output(array $argv): string
    {
        try {
            $result = $this->runner->run($argv, null, ['LC_ALL' => 'C'], null, 10.0);
        } catch (\RuntimeException) {
            return '';
        }

        return $result->isSuccess() ? $result->stdout : '';
    }

    private function cpuinfoThreads(): ?int
    {
        $count = preg_match_all('/^processor\s*:/m', (string) @file_get_contents($this->cpuinfoFile));

        return $count > 0 ? $count : null;
    }
}
