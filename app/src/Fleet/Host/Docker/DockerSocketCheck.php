<?php

declare(strict_types=1);

namespace App\Fleet\Host\Docker;

/**
 * FLE-298 (audit F3): the host's Docker socket must be `root:docker` mode 0660. A world-writable socket (a project's
 * `chmod 666 /var/run/docker.sock` hook does that) lets any local user, and any container that mounts it, drive Docker as root.
 *
 * check() only looks. restore() corrects mode and group and needs root: the `fleet` user cannot chmod a socket it does not own, so
 * for the watcher it reports `restored: false` and the host warning stays until `sudo fleet host:docker-socket --restore`.
 */
final readonly class DockerSocketCheck
{
    public const string PATH = '/var/run/docker.sock';
    public const int MODE = 0o660;

    public function __construct(private string $path = self::PATH)
    {
    }

    public function check(): DockerSocketState
    {
        clearstatcache(true, $this->path);
        $stat = @stat($this->path);
        if (false === $stat) {
            return new DockerSocketState($this->path, false, null, null, null, 'The Docker socket does not exist.');
        }
        $mode = $stat['mode'] & 0o7777;
        $owner = self::name('posix_getpwuid', $stat['uid']);
        $group = self::name('posix_getgrgid', $stat['gid']);
        $problems = [];
        if (self::MODE !== $mode) {
            $problems[] = \sprintf('mode is %04o, expected 0660', $mode);
        }
        if ('root' !== $owner) {
            $problems[] = \sprintf('owner is %s, expected root', $owner);
        }
        if ('docker' !== $group) {
            $problems[] = \sprintf('group is %s, expected docker', $group);
        }

        return new DockerSocketState($this->path, [] === $problems, $mode, $owner, $group, [] === $problems ? 'root:docker 0660' : implode('; ', $problems));
    }

    /**
     * Sets mode 0660 and group docker. Returns whether the socket is correct afterwards.
     */
    public function restore(): bool
    {
        if (!file_exists($this->path)) {
            return false;
        }
        if (\function_exists('posix_getgrnam') && false !== ($group = posix_getgrnam('docker'))) {
            @chgrp($this->path, $group['gid']);
        }
        @chmod($this->path, self::MODE);

        return $this->check()->ok;
    }

    private static function name(string $function, int $id): string
    {
        if (!\function_exists($function)) {
            return (string) $id;
        }
        $info = $function($id);

        return \is_array($info) ? (string) $info['name'] : (string) $id;
    }
}
