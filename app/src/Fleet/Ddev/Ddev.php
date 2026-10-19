<?php

declare(strict_types=1);

namespace App\Fleet\Ddev;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;

/**
 * The ddev and docker calls the fleet makes, all through the CommandRunner. Every ddev call runs without prompts and
 * without telemetry, and with the fleet user's HOME so a CLI run through sudo never reads another user's ~/.ddev.
 */
final readonly class Ddev
{
    public const float START_TIMEOUT = 3600.0;
    public const float LIST_TIMEOUT = 30.0;
    public const float STATS_TIMEOUT = 20.0;
    public const float DELETE_TIMEOUT = 600.0;

    private const array ENV = ['DDEV_NONINTERACTIVE' => 'true', 'DDEV_NO_INSTRUMENTATION' => 'true'];

    /** @var array<string, string> */
    private array $env;

    /**
     * @param string|null $home the fleet user's home (FLEET_USER_HOME); the process's own HOME when null or empty
     */
    public function __construct(private CommandRunner $runner, ?string $home = null)
    {
        $home = null === $home || '' === $home ? (string) getenv('HOME') : $home;
        if (!str_starts_with($home, '/')) {
            throw new \LogicException('Set FLEET_USER_HOME (or HOME) to the fleet user\'s home directory.');
        }
        $this->env = ['HOME' => $home] + self::ENV;
    }

    /**
     * @param callable(string, string): void $onOutput
     */
    public function start(string $dir, callable $onOutput, ?float $timeout = null): CommandResult
    {
        return $this->runner->run(['ddev', 'start'], $dir, $this->env, $onOutput, $timeout ?? self::START_TIMEOUT);
    }

    /**
     * @param callable(string, string): void $onOutput
     */
    public function stop(string $dir, callable $onOutput, ?float $timeout = null): CommandResult
    {
        return $this->runner->run(['ddev', 'stop'], $dir, $this->env, $onOutput, $timeout ?? self::START_TIMEOUT);
    }

    /**
     * @param callable(string, string): void $onOutput
     */
    public function restart(string $dir, callable $onOutput, ?float $timeout = null): CommandResult
    {
        return $this->runner->run(['ddev', 'restart'], $dir, $this->env, $onOutput, $timeout ?? self::START_TIMEOUT);
    }

    /**
     * Removes the project's containers and database volume (the code stays). Runs in the checkout; when that has no
     * `.ddev/config.yaml`, or the first try fails, once more by project name. A failure is a result, not an exception.
     *
     * @param callable(string, string): void $onOutput
     */
    public function delete(string $dir, string $project, callable $onOutput): CommandResult
    {
        if (1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $project)) {
            throw new \InvalidArgumentException('The project name is not a valid instance id.');
        }
        $base = ['ddev', 'delete', '--omit-snapshot', '--yes'];

        if (is_file($dir.'/.ddev/config.yaml')) {
            $result = $this->runner->run($base, $dir, $this->env, $onOutput, self::DELETE_TIMEOUT);
            if ($result->isSuccess()) {
                return $result;
            }
        }

        return $this->runner->run([...$base, $project], null, $this->env, $onOutput, self::DELETE_TIMEOUT);
    }

    /**
     * Runs one post-deploy line on the host: $argv is passed verbatim, never through a shell.
     *
     * @param list<string>                   $argv
     * @param array<string, string>          $env
     * @param callable(string, string): void $onOutput
     */
    public function run(string $dir, array $argv, array $env, callable $onOutput): CommandResult
    {
        return $this->runner->run($argv, $dir, $env + $this->env, $onOutput, self::START_TIMEOUT);
    }

    /**
     * @return array<string, DdevProject> by project name
     *
     * @throws DdevUnavailable on a timeout, a failure or output that is not a project list
     */
    public function list(): array
    {
        try {
            $result = $this->runner->run(['ddev', 'list', '--json-output'], null, $this->env, null, self::LIST_TIMEOUT);
        } catch (\Throwable $e) {
            throw new DdevUnavailable('ddev list did not answer.', 0, $e);
        }
        if (!$result->isSuccess()) {
            throw new DdevUnavailable('ddev list failed.');
        }

        $rows = self::projectRows($result->stdout);
        $projects = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['name'] ?? null)) {
                throw new DdevUnavailable('ddev list printed an unexpected project.');
            }
            $projects[$row['name']] = new DdevProject(
                $row['name'],
                \is_string($row['status'] ?? null) ? $row['status'] : '',
                \is_string($row['approot'] ?? null) ? $row['approot'] : '',
            );
        }

        return $projects;
    }

    /**
     * Memory in MiB per project, summed over its containers (`ddev-<project>-<service>`). An empty array when docker
     * does not answer.
     *
     * @return array<string, int>
     */
    public function ramUsage(): array
    {
        try {
            $result = $this->runner->run(['docker', 'stats', '--no-stream', '--format', '{{json .}}'], null, [], null, self::STATS_TIMEOUT);
        } catch (\Throwable) {
            return [];
        }
        if (!$result->isSuccess()) {
            return [];
        }

        $mib = [];
        foreach (preg_split('/\R/', $result->stdout, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!\is_array($row) || !\is_string($row['Name'] ?? null) || !\is_string($row['MemUsage'] ?? null)) {
                continue;
            }
            if (1 !== preg_match('/^ddev-(.+)-[a-z0-9]+$/', $row['Name'], $name) || 1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $name[1])) {
                continue;
            }
            $bytes = self::bytes(explode(' / ', $row['MemUsage'])[0]);
            if (null !== $bytes) {
                $mib[$name[1]] = ($mib[$name[1]] ?? 0.0) + $bytes / 1048576;
            }
        }

        return array_map(static fn (float $v): int => (int) round($v), $mib);
    }

    /**
     * Whether a start failed because a port or the container network could not be set up (worth one retry).
     */
    public static function isPortConflict(string $output): bool
    {
        return 1 === preg_match('/port is already allocated|failed to set up container networking/i', $output);
    }

    /**
     * `ddev list --json-output` prints one JSON log object per line, even when there is nothing to list; the projects
     * are the `raw` field of the last JSON line (or the line is a plain list).
     *
     * @return list<mixed>
     */
    private static function projectRows(string $stdout): array
    {
        $lines = preg_split('/\R/', trim($stdout), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (!\is_array($decoded)) {
                continue;
            }
            $rows = array_is_list($decoded) ? $decoded : ($decoded['raw'] ?? null);
            if (\is_array($rows)) {
                return array_values($rows);
            }
        }

        throw new DdevUnavailable('ddev list printed no project list.');
    }

    private static function bytes(string $text): ?float
    {
        if (1 !== preg_match('/^\s*([0-9.]+)\s*([KMGT]?i?B)\s*$/i', $text, $m)) {
            return null;
        }
        $factor = match (strtoupper($m[2])) {
            'B' => 1,
            'KB', 'KIB' => 1024,
            'MB', 'MIB' => 1048576,
            'GB', 'GIB' => 1073741824,
            default => 1099511627776,
        };

        return (float) $m[1] * $factor;
    }
}
