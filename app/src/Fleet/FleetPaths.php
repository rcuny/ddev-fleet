<?php

declare(strict_types=1);

namespace App\Fleet;

use App\Domain\Ssh\SshKeyRole;

/**
 * Single source of truth for where fleet state lives under FLEET_HOME.
 *
 * Pure string logic; ensureLayout() is the only method that touches the filesystem.
 */
final readonly class FleetPaths
{
    private const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';
    /** An instance id is `{project}--{label}`: lowercase words joined by single dashes, at most 63 characters (a DNS label). */
    public const string INSTANCE_ID_PATTERN = '/^(?=.{3,63}$)[a-z0-9]+(?:-[a-z0-9]+)*--[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    private string $home;
    private string $caddyDir;

    /**
     * @param string $caddyDir the directory Caddy imports the fleet snippets from (FLEET_CADDY_DIR, /etc/caddy/fleet in
     *                         production). It is deliberately a required argument: a default would let a test or a
     *                         misconfigured service silently write into the real directory.
     */
    public function __construct(string $home, string $caddyDir)
    {
        $this->home = self::absoluteDir($home, 'FLEET_HOME');
        $this->caddyDir = self::absoluteDir($caddyDir, 'FLEET_CADDY_DIR');
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

    /**
     * Database backups (`fleet-YYYYmmdd-HHMMSS.sqlite`, `queue-…`), written by BackupService. Owner only.
     */
    public function backupsDir(): string
    {
        return $this->home.'/backups';
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

    /**
     * Job logs: `logs/jobs/<job>.log` for fleet-level jobs, `logs/instances/<instance>/<job>.log` for jobs that
     * belong to an instance. The two fixed directory names cannot collide with an instance id.
     */
    public function jobLog(string $jobId, ?string $instanceId = null): string
    {
        $dir = null === $instanceId ? 'jobs' : 'instances/'.self::id($instanceId, 'instance');

        return $this->logsDir().'/'.$dir.'/'.self::id($jobId, 'job').'.log';
    }

    public function locksDir(): string
    {
        return $this->home.'/locks';
    }

    /**
     * Where the app renders config for the root helper (`apply <target>`) to validate and install. Owner only.
     */
    public function stagingDir(): string
    {
        return $this->home.'/staging';
    }

    public function stagingFile(string $target, string $name): string
    {
        return $this->stagingDir().'/'.self::id($target, 'staging target').'/'.self::id($name, 'staging file');
    }

    /**
     * Authelia's users file lives here; the Authelia service reads it through a group, so it is not owner-only.
     */
    public function autheliaDir(): string
    {
        return $this->home.'/authelia';
    }

    public function usersFile(): string
    {
        return $this->autheliaDir().'/users.yml';
    }

    public function caddySnippetDir(): string
    {
        return $this->caddyDir;
    }

    /**
     * Where the worker writes one Caddy site snippet per instance (the main Caddyfile imports `instances/*.conf`).
     */
    public function caddyInstancesDir(): string
    {
        return $this->caddyDir.'/instances';
    }

    public function instanceSiteFile(string $id): string
    {
        if (1 !== preg_match(self::INSTANCE_ID_PATTERN, $id)) {
            throw new \InvalidArgumentException(\sprintf('Invalid instance id "%s".', addcslashes($id, "\0..\37")));
        }

        return $this->caddyInstancesDir().'/'.$id.'.conf';
    }

    public function gateFile(): string
    {
        return $this->caddyDir.'/gate.conf';
    }

    /**
     * The main Caddyfile sits next to the snippet directory (/etc/caddy/Caddyfile for /etc/caddy/fleet).
     */
    public function caddyMainFile(): string
    {
        return rtrim(\dirname($this->caddyDir), '/').'/Caddyfile';
    }

    public function knownHostsFile(): string
    {
        return $this->sshDir().'/known_hosts';
    }

    public function sshPrivateKey(SshKeyRole $role): string
    {
        return $this->sshDir().'/'.$role->value.'_ed25519';
    }

    public function sshPublicKey(SshKeyRole $role): string
    {
        return $this->sshPrivateKey($role).'.pub';
    }

    /**
     * Creates the directory skeleton. Modes are applied with chmod so they are exact under any umask.
     */
    public function ensureLayout(): void
    {
        // dir => [mode, repair]: `repair` directories (state and secrets) get their mode corrected
        // even when they already exist; the others are only chmod'ed when created here.
        // Everything that may hold secrets (DB dumps, injected .env files, job logs that echo them) is 0750 or
        // tighter. Which user and group own the tree is decided in M0.02 (php-fpm and the worker must both write).
        $dirs = [
            $this->home => [0o750, false],
            $this->varDir() => [0o750, true],
            $this->keysDir() => [0o700, true],
            $this->sshDir() => [0o700, true],
            // Owner only: copies of the database (password hashes, sealed secrets).
            $this->backupsDir() => [0o700, true],
            $this->locksDir() => [0o755, false],
            $this->logsDir() => [0o750, true],
            $this->logsDir().'/jobs' => [0o750, true],
            $this->logsDir().'/instances' => [0o750, true],
            $this->home.'/assets' => [0o750, true],
            $this->instancesDir() => [0o750, true],
            // Owner only: rendered config (may carry credentials) waits here for the root helper.
            $this->stagingDir() => [0o700, true],
            // Created by the installer as fleet:authelia 2750 (setgid); a dev checkout gets plain 0750. Never repaired,
            // so the installer's group and setgid bit survive.
            $this->autheliaDir() => [0o750, false],
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

    private static function absoluteDir(string $path, string $what): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException(\sprintf('%s must be an absolute path.', $what));
        }
        $path = rtrim($path, '/');
        if ('' === $path) {
            throw new \InvalidArgumentException(\sprintf('%s must not be the filesystem root.', $what));
        }
        foreach (explode('/', $path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException(\sprintf('%s must be a normalised path.', $what));
            }
        }

        return $path;
    }

    private static function id(string $value, string $what): string
    {
        if (1 !== preg_match(self::ID_PATTERN, $value)) {
            throw new \InvalidArgumentException(\sprintf('Invalid %s id "%s".', $what, addcslashes($value, "\0..\37")));
        }

        return $value;
    }
}
