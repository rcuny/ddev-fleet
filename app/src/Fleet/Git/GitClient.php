<?php

declare(strict_types=1);

namespace App\Fleet\Git;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\Ddev\DdevPaths;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Ssh\AtomicFile;

/**
 * The git calls the fleet makes, all through the CommandRunner. Branch and URL are hostile input: a value that could pass
 * as an option is refused before anything runs, `--` ends the options, and only https and ssh transports are allowed.
 */
final readonly class GitClient
{
    public const float CLONE_TIMEOUT = 1800.0;
    public const float HEAD_TIMEOUT = 10.0;

    /** The pinned known_hosts the container reads, relative to the checkout (the container sees it under DdevPaths::CONTAINER_APPROOT). */
    public const string CONTAINER_KNOWN_HOSTS = '.ddev/fleet_known_hosts';

    /**
     * FLE-271 review: no change needed. Inside the container git offers only the instance's agent keys (no -i, so the push key
     * reaches it via the agent, never via a host path); on the host core.sshCommand names -i push/deploy with IdentitiesOnly. Both
     * are pinned by GitClientTest.
     *
     * What git runs inside the web container (`GIT_SSH_COMMAND`, set in config.fleet.yaml; the environment wins over
     * `core.sshCommand`, which holds host paths). No identity option: the keys come from the instance's own ssh-agent (SSH_AUTH_SOCK points at its
     * socket, FLE-145; never DDEV's shared agent, see docs/reference/14-ssh-keys.md), and `IdentitiesOnly` would ignore them. Strict, pinned, never prompts. No quotes and no `$`: DDEV copies web_environment into a generated compose file and
     * a double quote there breaks it (FLE-188).
     */
    public const string CONTAINER_SSH_COMMAND = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile='.DdevPaths::CONTAINER_APPROOT.'/'.self::CONTAINER_KNOWN_HOSTS;

    public function __construct(private CommandRunner $runner, private FleetPaths $paths)
    {
    }

    /**
     * Clones $branch of $url into $dir (exactly FleetPaths::instanceDir() of a valid instance id), authenticated with the host's deploy key.
     *
     * @param callable(string, string): void $onOutput
     *
     * @throws \InvalidArgumentException when a value is not safe to pass to git
     */
    public function clone(string $url, string $branch, string $dir, callable $onOutput): CommandResult
    {
        foreach (['url' => $url, 'branch' => $branch] as $what => $value) {
            if ('' === $value || '-' === $value[0] || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new \InvalidArgumentException(\sprintf('The %s is not valid.', $what));
            }
        }
        $id = basename($dir);
        if (1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $id) || $dir !== $this->paths->instanceDir($id)) {
            throw new \InvalidArgumentException('The target directory is not an instance directory.');
        }

        $ssh = $this->sshCommand(false);

        return $this->runner->run(
            ['git', '-c', 'protocol.ext.allow=never', '-c', 'protocol.file.allow=never', 'clone', '--branch', $branch, '--', $url, $dir],
            $this->paths->instancesDir(),
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => 'https:ssh', 'GIT_SSH_COMMAND' => $ssh],
            $onOutput,
            self::CLONE_TIMEOUT,
        );
    }

    /**
     * FLE-319: a throw-away shallow clone of $branch of $url into $dir (a direct child of FleetPaths::stagingDir()), only so the
     * pre-start guard can scan the files a redeploy would bring before the running instance is touched.
     *
     * @param callable(string, string): void $onOutput
     *
     * @throws \InvalidArgumentException when a value is not safe to pass to git or $dir is not a staging child
     */
    public function cloneForScan(string $url, string $branch, string $dir, callable $onOutput): CommandResult
    {
        foreach (['url' => $url, 'branch' => $branch] as $what => $value) {
            if ('' === $value || '-' === $value[0] || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new \InvalidArgumentException(\sprintf('The %s is not valid.', $what));
            }
        }
        if (\dirname($dir) !== $this->paths->stagingDir() || 1 !== preg_match('/^redeploy-[a-z0-9-]+$/', basename($dir))) {
            throw new \InvalidArgumentException('The target directory is not a staging directory.');
        }

        return $this->runner->run(
            ['git', '-c', 'protocol.ext.allow=never', '-c', 'protocol.file.allow=never', 'clone', '--depth', '1', '--branch', $branch, '--', $url, $dir],
            $this->paths->stagingDir(),
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => 'https:ssh', 'GIT_SSH_COMMAND' => $this->sshCommand(false)],
            $onOutput,
            self::CLONE_TIMEOUT,
        );
    }

    /**
     * Makes git over SSH work in a checkout's terminals (tty1/tty2, CI scripts): `core.sshCommand` carries the same
     * options as the clone, so no `~/.ssh` is needed and a missing host trust fails fast (BatchMode) instead of
     * hanging at a host-key prompt. The push key, when the host has one, is offered first so agent instances can push.
     *
     * @throws \InvalidArgumentException when $dir is not an instance directory
     */
    public function configureSsh(string $dir): CommandResult
    {
        $id = basename($dir);
        if (1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $id) || $dir !== $this->paths->instanceDir($id)) {
            throw new \InvalidArgumentException('The target directory is not an instance directory.');
        }

        return $this->runner->run(['git', 'config', '--local', 'core.sshCommand', $this->sshCommand(true)], $dir, [], null, self::HEAD_TIMEOUT);
    }

    /**
     * Copies Fleet's pinned known_hosts (public host keys, not a secret) into the checkout for the web container; an
     * empty file when the host has none, so a strict ssh fails fast rather than falling back to a prompt.
     *
     * @return string the path written, relative to $dir
     *
     * @throws \InvalidArgumentException when $dir is not an instance directory
     * @throws \RuntimeException         when the file cannot be written
     */
    public function writeContainerKnownHosts(string $dir): string
    {
        $id = basename($dir);
        if (1 !== preg_match(FleetPaths::INSTANCE_ID_PATTERN, $id) || $dir !== $this->paths->instanceDir($id)) {
            throw new \InvalidArgumentException('The target directory is not an instance directory.');
        }
        $source = $this->paths->knownHostsFile();
        $text = is_file($source) ? (string) file_get_contents($source) : '';
        $target = $dir.'/'.self::CONTAINER_KNOWN_HOSTS;
        if (!is_dir(\dirname($target)) && !@mkdir(\dirname($target), 0o750, true) && !is_dir(\dirname($target))) {
            throw new \RuntimeException('Cannot create the .ddev directory.');
        }
        AtomicFile::write($target, $text, 0o644);

        return self::CONTAINER_KNOWN_HOSTS;
    }

    private function sshCommand(bool $withPushKey): string
    {
        $keys = [];
        if ($withPushKey && is_file($this->paths->sshPrivateKey(SshKeyRole::Push))) {
            $keys[] = $this->paths->sshPrivateKey(SshKeyRole::Push);
        }
        $keys[] = $this->paths->sshPrivateKey(SshKeyRole::Deploy);

        return \sprintf(
            'ssh %s-o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%s',
            implode('', array_map(static fn (string $k): string => '-i '.escapeshellarg($k).' ', $keys)),
            escapeshellarg($this->paths->knownHostsFile()),
        );
    }

    /**
     * The abbreviated HEAD commit of a checkout; an empty string when it cannot be read. Never throws.
     */
    public function head(string $dir): string
    {
        try {
            $result = $this->runner->run(['git', 'rev-parse', '--short', 'HEAD'], $dir, [], null, self::HEAD_TIMEOUT);
        } catch (\Throwable) {
            return '';
        }

        return $result->isSuccess() ? trim($result->stdout) : '';
    }

    /**
     * The local branches of a checkout, the checked-out one flagged; an empty list when they cannot be read. Never throws.
     *
     * @return list<array{name: string, current: bool}>
     */
    public function localBranches(string $dir): array
    {
        try {
            $result = $this->runner->run(['git', 'for-each-ref', '--format=%(HEAD)%(refname:short)', 'refs/heads'], $dir, [], null, self::HEAD_TIMEOUT);
        } catch (\Throwable) {
            return [];
        }
        if (!$result->isSuccess()) {
            return [];
        }

        $branches = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $line = rtrim($line, "\r");
            if ('' !== trim($line)) {
                $branches[] = ['name' => ltrim($line, '* '), 'current' => str_starts_with($line, '*')];
            }
        }

        return $branches;
    }
}
