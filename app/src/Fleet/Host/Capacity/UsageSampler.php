<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Measures the CPU use of the capacity slices from cgroup v2 `cpu.stat` (FLE-200, spec 5): used cores = the delta of
 * `usage_usec` between two samples divided by the elapsed time; `nr_throttled` rising means the quota bit. Read-only
 * and rootless (the fleet user can read /sys/fs/cgroup). The status watcher calls sample() every few seconds; the
 * result and the raw counters live in one small JSON file under FLEET_HOME so other processes (fleet:host:capacity, the
 * host page) read it without sampling. A reading older than STALE_SECONDS counts as unknown.
 */
final class UsageSampler
{
    public const int STALE_SECONDS = 120;

    public function __construct(
        #[Autowire('%fleet.cgroup_root%')]
        private readonly string $cgroupRoot,
        #[Autowire('%fleet.capacity_usage_file%')]
        private readonly string $file,
    ) {
    }

    /**
     * Reads the slices now and stores the usage since the previous sample.
     *
     * @return array<string, array{used_cores: float, throttled: bool}> by slice unit name
     */
    public function sample(\DateTimeImmutable $now): array
    {
        $counters = $this->counters();
        $previous = $this->load();
        $at = (float) $now->format('U.u');

        $used = [];
        if (null !== $previous && $at > $previous['at']) {
            $elapsed = $at - $previous['at'];
            foreach ($counters as $unit => $c) {
                $before = $previous['counters'][$unit] ?? null;
                if (null === $before || $c['usec'] < $before['usec']) {
                    continue; // new slice, or its counters restarted
                }
                $used[$unit] = [
                    'used_cores' => round(($c['usec'] - $before['usec']) / 1_000_000 / $elapsed, 3),
                    'throttled' => $c['throttled'] > $before['throttled'],
                ];
            }
        }

        $this->store(['at' => $at, 'counters' => $counters, 'used' => $used]);

        return $used;
    }

    /**
     * The last stored usage, or [] when there is none or it is stale.
     *
     * @return array<string, array{used_cores: float, throttled: bool}> by slice unit name
     */
    public function usage(\DateTimeImmutable $now): array
    {
        $data = $this->load();
        if (null === $data || (float) $now->format('U.u') - $data['at'] > self::STALE_SECONDS) {
            return [];
        }

        return $data['used'];
    }

    /**
     * @return array<string, array{usec: int, throttled: int}> the root slice and every slice below it, by unit name
     */
    private function counters(): array
    {
        $found = [];
        $this->walk($this->cgroupRoot, 0, $found);

        return $found;
    }

    /**
     * @param array<string, array{usec: int, throttled: int}> $found
     */
    private function walk(string $dir, int $depth, array &$found): void
    {
        $stat = $this->readStat($dir.'/cpu.stat');
        if (null !== $stat) {
            $found[basename($dir)] = $stat;
        }
        if ($depth >= 2) { // fleet.slice / project / template
            return;
        }
        foreach (@scandir($dir) ?: [] as $entry) {
            if (1 === preg_match('/^fleet(-[a-z0-9_]+){1,2}\.slice$/D', $entry) && is_dir($dir.'/'.$entry)) {
                $this->walk($dir.'/'.$entry, $depth + 1, $found);
            }
        }
    }

    /**
     * @return array{usec: int, throttled: int}|null
     */
    private function readStat(string $path): ?array
    {
        $text = @file_get_contents($path);
        if (!\is_string($text) || 1 !== preg_match('/^usage_usec\s+([0-9]+)$/m', $text, $usec)) {
            return null;
        }
        $throttled = 1 === preg_match('/^nr_throttled\s+([0-9]+)$/m', $text, $t) ? (int) $t[1] : 0;

        return ['usec' => (int) $usec[1], 'throttled' => $throttled];
    }

    /**
     * @return array{at: float, counters: array<string, array{usec: int, throttled: int}>, used: array<string, array{used_cores: float, throttled: bool}>}|null
     */
    private function load(): ?array
    {
        $text = @file_get_contents($this->file);
        $data = \is_string($text) ? json_decode($text, true) : null;
        if (!\is_array($data) || !isset($data['at']) || !is_numeric($data['at']) || !\is_array($data['counters'] ?? null) || !\is_array($data['used'] ?? null)) {
            return null;
        }

        /* @var array{at: float, counters: array<string, array{usec: int, throttled: int}>, used: array<string, array{used_cores: float, throttled: bool}>} $data */
        $data['at'] = (float) $data['at'];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(array $data): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $this->file.'.'.getmypid().'.tmp';
        if (false !== @file_put_contents($tmp, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION))) {
            @chmod($tmp, 0o644);
            @rename($tmp, $this->file);
        }
    }
}
