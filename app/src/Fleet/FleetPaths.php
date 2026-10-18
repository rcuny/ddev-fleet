<?php

declare(strict_types=1);

namespace App\Fleet;

/**
 * Single source of truth for where fleet state lives under FLEET_HOME.
 *
 * Pure string logic; ensureLayout() is the only method that touches the filesystem.
 */
final readonly class FleetPaths
{
    private const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    private string $home;

    public function __construct(string $home)
    {
        if (!str_starts_with($home, '/') || str_contains($home, "\0")) {
            throw new \InvalidArgumentException('FLEET_HOME must be an absolute path.');
        }
        $home = rtrim($home, '/');
        if ('' === $home) {
            throw new \InvalidArgumentException('FLEET_HOME must not be the filesystem root.');
        }
        foreach (explode('/', $home) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException('FLEET_HOME must be a normalised path.');
            }
        }
        $this->home = $home;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function varDir(): string
    {
        return $this->home.'/var';
    }

    public function fleetDb(): string
    {
        return $this->varDir().'/fleet.sqlite';
    }

    public function queueDb(): string
    {
        return $this->varDir().'/queue.sqlite';
    }

    public function keysDir(): string
    {
        return $this->home.'/keys';
    }

    public function secretKey(): string
    {
        return $this->keysDir().'/secret.key';
    }

    public function sshDir(): string
    {
        return $this->home.'/ssh';
    }

    public function assetsDir(string $project): string
    {
        return $this->home.'/assets/'.self::id($project, 'project');
    }

    public function instancesDir(): string
    {
        return $this->home.'/instances';
    }

    public function instanceDir(string $id): string
    {
        return $this->instancesDir().'/'.self::id($id, 'instance');
    }

    public function logsDir(): string
    {
        return $this->home.'/logs';
    }

    public function jobLog(string $jobId, ?string $instanceId = null): string
    {
        $dir = null === $instanceId ? 'jobs' : self::id($instanceId, 'instance');

        return $this->logsDir().'/'.$dir.'/'.self::id($jobId, 'job').'.log';
    }

    public function locksDir(): string
    {
        return $this->home.'/locks';
    }

    /**
     * Creates the directory skeleton. Modes are applied with chmod so they are exact under any umask.
     */
    public function ensureLayout(): void
    {
        // dir => [mode, repair]: `repair` directories (state and secrets) get their mode corrected
        // even when they already exist; the others are only chmod'ed when created here.
        $dirs = [
            $this->home => [0o755, false],
            $this->varDir() => [0o750, true],
            $this->keysDir() => [0o700, true],
            $this->sshDir() => [0o700, true],
            $this->locksDir() => [0o755, false],
            $this->logsDir() => [0o755, false],
            $this->logsDir().'/jobs' => [0o755, false],
            $this->home.'/assets' => [0o755, false],
            $this->instancesDir() => [0o755, false],
        ];

        foreach ($dirs as $dir => [$mode, $repair]) {
            $existed = is_dir($dir);
            if (!$existed && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $dir));
            }
            if (($existed && !$repair) || (@fileperms($dir) & 0o777) === $mode) {
                continue;
            }
            if (!@chmod($dir, $mode)) {
                throw new \RuntimeException(\sprintf('Cannot set mode %04o on "%s".', $mode, $dir));
            }
        }
    }

    private static function id(string $value, string $what): string
    {
        if (1 !== preg_match(self::ID_PATTERN, $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid %s id "%s".', $what, addcslashes($value, "\0..\37")));
        }

        return $value;
    }
}
