<?php

declare(strict_types=1);

namespace App\Fleet\Ddev;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
        if (1 !== preg_match(FleetPaths::EXISTING_INSTANCE_ID_PATTERN, $project)) {
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
            if (1 !== preg_match('/^ddev-(.+)-[a-z0-9]+$/', $row['Name'], $name) || 1 !== preg_match(FleetPaths::EXISTING_INSTANCE_ID_PATTERN, $name[1])) {
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
     * The names of all services of a project, from `ddev debug compose-config`: web, db, add-ons and profile services
     * (xhgui). Works on a stopped project, once the add-on files are in place.
     *
     * @return list<string>
     *
     * @throws DdevUnavailable when the command fails or prints no services
     */
    public function composeServices(string $dir): array
    {
        try {
            $result = $this->runner->run(['ddev', 'debug', 'compose-config'], $dir, $this->env, null, self::LIST_TIMEOUT * 2);
        } catch (\Throwable $e) {
            throw new DdevUnavailable('ddev debug compose-config did not answer.', 0, $e);
        }
        if (!$result->isSuccess()) {
            throw new DdevUnavailable(\sprintf('ddev debug compose-config failed (exit %d).', $result->exitCode));
        }
        try {
            $config = Yaml::parse($result->stdout);
        } catch (ParseException $e) {
            throw new DdevUnavailable('ddev debug compose-config printed no valid YAML.', 0, $e);
        }
        $services = \is_array($config) && \is_array($config['services'] ?? null) ? array_keys($config['services']) : [];
        $services = array_map('strval', $services);
        if ([] === $services) {
            throw new DdevUnavailable('ddev debug compose-config listed no services.');
        }
        foreach ($services as $service) {
            if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $service)) {
                throw new DdevUnavailable('ddev debug compose-config listed a service with an unusable name.');
            }
        }

        return $services;
    }

    /**
     * The installed DDEV version (`ddev --version`, "ddev version v1.24.10"), without the "v"; null when unreadable.
     */
    public function ddevVersion(): ?string
    {
        return $this->versionOf(['ddev', '--version']);
    }

    /**
     * The installed docker compose plugin version (`docker compose version --short`), without the "v"; null when unreadable.
     */
    public function composeVersion(): ?string
    {
        return $this->versionOf(['docker', 'compose', 'version', '--short']);
    }

    /**
     * The Docker networks a container is attached to (`docker inspect`), or null when the container does not exist.
     *
     * @return list<string>|null
     *
     * @throws DdevUnavailable when docker answers with something that is not a network map
     */
    public function containerNetworks(string $container): ?array
    {
        try {
            $result = $this->runner->run(['docker', 'inspect', '--format', '{{json .NetworkSettings.Networks}}', $container], null, [], null, self::STATS_TIMEOUT);
        } catch (\Throwable $e) {
            throw new DdevUnavailable('docker inspect '.$container.' did not answer.', 0, $e);
        }
        if (!$result->isSuccess()) {
            return null;
        }
        $networks = json_decode(trim($result->stdout), true);
        if (!\is_array($networks)) {
            throw new DdevUnavailable('docker inspect '.$container.' printed no network map.');
        }

        return array_map('strval', array_keys($networks));
    }

    /**
     * The containers of a DDEV project, running or not (`docker ps -a`, matched by DDEV's own `com.ddev.site-name` label).
     * `routed` is true for a non-db container carrying a traefik router label key (the db is never routed, FLE-307 review): the router must reach it over ddev_default (FLE-307).
     *
     * @return list<array{name: string, service: string, routed: bool}>
     */
    public function projectContainers(string $project): array
    {
        try {
            $result = $this->runner->run(['docker', 'ps', '-a', '--filter', 'label=com.ddev.site-name='.$project, '--format', '{{.Names}}|{{.Label "com.docker.compose.service"}}|{{.Labels}}'], null, [], null, self::STATS_TIMEOUT);
        } catch (\Throwable $e) {
            throw new DdevUnavailable('docker ps did not answer.', 0, $e);
        }
        if (!$result->isSuccess()) {
            throw new DdevUnavailable('docker ps failed.');
        }
        $rows = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            $parts = explode('|', $line, 3);
            if ('' === $parts[0] || 3 !== \count($parts)) {
                continue;
            }
            $rows[] = ['name' => $parts[0], 'service' => $parts[1], 'routed' => 'db' !== $parts[1] && self::hasRouterLabel($parts[2])];
        }

        return $rows;
    }

    /**
     * Whether a `docker ps` label list ("k=v,k=v") carries a traefik router label KEY (`traefik.enable`, `traefik.http.routers.*`).
     * Only keys count: a value that merely contains "traefik." (or a spoofed label on a service that never needs the router) says nothing.
     */
    public static function hasRouterLabel(string $labels): bool
    {
        foreach (explode(',', $labels) as $pair) {
            $key = trim(explode('=', $pair, 2)[0]);
            if ('traefik.enable' === $key || str_starts_with($key, 'traefik.http.routers.') || str_starts_with($key, 'traefik.tcp.routers.')) {
                return true;
            }
        }

        return false;
    }

    public function disconnectNetwork(string $network, string $container): bool
    {
        try {
            return $this->runner->run(['docker', 'network', 'disconnect', $network, $container], null, [], null, self::STATS_TIMEOUT)->isSuccess();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function versionOf(array $argv): ?string
    {
        try {
            $result = $this->runner->run($argv, null, $this->env, null, self::STATS_TIMEOUT);
        } catch (\Throwable) {
            return null;
        }

        return $result->isSuccess() && 1 === preg_match('/v?(\d+\.\d+\.\d+)/', $result->stdout, $m) ? $m[1] : null;
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
