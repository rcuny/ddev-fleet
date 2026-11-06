<?php

declare(strict_types=1);

namespace App\Fleet\Host\Capacity;

use App\Fleet\Shell\CommandRunner;

/**
 * How many Docker bridge networks the host has and how many its address pools can hold (FLE-219). Every DDEV
 * instance makes one bridge network, and Docker's built-in pools hold about 30 ("all predefined address pools have been
 * fully subnetted"). Reads `docker info` (the pools the RUNNING daemon uses; a changed daemon.json counts only after
 * a Docker restart) and `docker network ls` through the CommandRunner seam. Null = unknown (no docker, daemon down).
 */
final readonly class DockerNetworkProbe
{
    /** Docker's built-in pools: 172.17.0.0/16 to 172.31.0.0/16 in /16s (15) and 192.168.0.0/16 in /20s (16). */
    public const int BUILT_IN_CAPACITY = 31;

    public function __construct(private CommandRunner $runner)
    {
    }

    /** @return array{used: int, max: int}|null */
    public function read(): ?array
    {
        $info = $this->output(['docker', 'info', '--format', '{{json .DefaultAddressPools}}']);
        $networks = $this->output(['docker', 'network', 'ls', '--filter', 'driver=bridge', '--quiet']);
        // `docker info` always prints the pools ("null" when none are set): empty output is no answer.
        if (null === $info || '' === trim($info) || null === $networks) {
            return null;
        }

        return ['used' => \count(array_filter(array_map('trim', explode("\n", $networks)))), 'max' => self::capacity($info)];
    }

    /** The networks the pools hold: the sum of 2^(size - prefix) per pool; no pool configured = the built-in ones. */
    public static function capacity(string $poolsJson): int
    {
        $pools = json_decode(trim($poolsJson), true);
        if (!\is_array($pools) || [] === $pools) {
            return self::BUILT_IN_CAPACITY;
        }

        $total = 0;
        foreach ($pools as $pool) {
            if (!\is_array($pool) || !\is_string($pool['Base'] ?? null) || !\is_int($pool['Size'] ?? null)) {
                continue;
            }
            $prefix = (int) substr($pool['Base'], (int) strrpos($pool['Base'], '/') + 1);
            if ($pool['Size'] >= $prefix && $pool['Size'] <= 30 && $pool['Size'] - $prefix <= 24) {
                $total += 1 << ($pool['Size'] - $prefix);
            }
        }

        return $total > 0 ? $total : self::BUILT_IN_CAPACITY;
    }

    /** @param list<string> $argv */
    private function output(array $argv): ?string
    {
        try {
            $result = $this->runner->run($argv, null, ['LC_ALL' => 'C'], null, 10.0);
        } catch (\RuntimeException) {
            return null;
        }

        return $result->isSuccess() ? $result->stdout : null;
    }
}
