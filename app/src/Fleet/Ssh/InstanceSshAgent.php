<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use Symfony\Component\Yaml\Yaml;

/**
 * A dedicated ssh-agent per instance (FLE-145), for projects that need git over SSH from inside the web container.
 *
 * Why not DDEV's own agent: `ddev-ssh-agent` is one container per host and its socket is mounted into the web container
 * of EVERY DDEV project, so a key loaded there is usable by code Fleet does not control. Here Fleet runs `ssh-agent` as
 * its own user, with the socket in a directory only that instance's container mounts (compose override, see
 * composeOverride()), loads only the push key and with a lifetime, and stops the agent with the instance. Nothing is ever
 * loaded into DDEV's agent. A key cannot be read out of an agent, but whoever reaches the socket can sign with it:
 * that is why the socket belongs to one instance of one opted-in project.
 *
 * Everything is idempotent: start() reuses a live agent and refreshes the key lifetime, stop() does nothing when there is
 * no agent. The agent does not survive a reboot; the next start (deploy or `fleet instance:start`) recreates it.
 */
final readonly class InstanceSshAgent
{
    /** Seconds a key stays loaded (`ssh-add -t`); every start and deploy refreshes it. */
    public const int KEY_LIFETIME = 86400;

    /** Where the socket directory appears in the container, and so what SSH_AUTH_SOCK points at. */
    public const string CONTAINER_DIR = '/run/fleet-ssh-agent';
    public const string CONTAINER_SOCKET = self::CONTAINER_DIR.'/agent.sock';

    public const string OVERRIDE_FILE = '.ddev/docker-compose.fleet-ssh.yaml';

    private const float TIMEOUT = 20.0;

    public function __construct(private CommandRunner $runner, private FleetPaths $paths)
    {
    }

    /**
     * Whether the host has the key an agent project needs.
     */
    public function hasKey(): bool
    {
        return is_file($this->paths->sshPrivateKey(SshKeyRole::Push));
    }

    /**
     * Makes sure the instance's agent runs and holds the push key. The socket directory exists afterwards (0700) even
     * when this throws, so a container that starts anyway never makes Docker create a root-owned directory there.
     *
     * @throws \RuntimeException when the agent cannot be started or the key cannot be loaded
     */
    public function start(string $instanceId): void
    {
        $dir = $this->paths->agentSocketDir($instanceId);
        $this->makeDir($this->paths->runDir());
        $this->makeDir($dir);

        $socket = $this->paths->agentSocket($instanceId);
        if (!$this->alive($socket)) {
            @unlink($socket);
            $started = $this->runner->run(['ssh-agent', '-a', $socket], null, [], null, self::TIMEOUT);
            if (!$started->isSuccess() || 1 !== preg_match('/SSH_AGENT_PID=(\d+)/', $started->stdout, $m)) {
                throw new \RuntimeException(\sprintf('ssh-agent did not start (exit %d).', $started->exitCode));
            }
            $this->writePid($instanceId, $m[1]);
        }

        $key = $this->paths->sshPrivateKey(SshKeyRole::Push);
        $added = $this->runner->run(['ssh-add', '-t', (string) self::KEY_LIFETIME, $key], null, ['SSH_AUTH_SOCK' => $socket], null, self::TIMEOUT);
        if (!$added->isSuccess()) {
            throw new \RuntimeException(\sprintf('The push key could not be loaded into the instance\'s ssh-agent (exit %d).', $added->exitCode));
        }
    }

    /**
     * Stops the instance's agent and removes its socket directory. Never throws: a destroy must finish.
     */
    public function stop(string $instanceId): void
    {
        $socket = $this->paths->agentSocket($instanceId);
        $pidFile = $this->paths->agentPidFile($instanceId);
        $pid = is_file($pidFile) ? trim((string) @file_get_contents($pidFile)) : '';

        // The pid is only trusted while the socket still answers, so a pid that was reused by another process is never signalled.
        if (1 === preg_match('/^\d+$/', $pid) && $this->alive($socket)) {
            try {
                $this->runner->run(['ssh-agent', '-k'], null, ['SSH_AGENT_PID' => $pid, 'SSH_AUTH_SOCK' => $socket], null, self::TIMEOUT);
            } catch (CommandRunnerException) {
                // The directory is removed below either way.
            }
        }
        @unlink($socket);
        @rmdir($this->paths->agentSocketDir($instanceId));
        @unlink($pidFile);
    }

    /**
     * The compose file that gives ONE project's web container the agent socket and nothing else. DDEV merges every
     * `.ddev/docker-compose.*.yaml`; the container's own SSH_AUTH_SOCK replaces the one DDEV sets for its global agent.
     */
    public function composeOverride(string $instanceId): string
    {
        $dir = $this->paths->agentSocketDir($instanceId);

        // Dumped through the YAML component, so no path can break out of the file.
        return "# Written by fleet (FLE-145): this instance's own ssh-agent socket, mounted into this web container only.\n"
            .Yaml::dump(['services' => ['web' => [
                'volumes' => [$dir.':'.self::CONTAINER_DIR],
                'environment' => ['SSH_AUTH_SOCK='.self::CONTAINER_SOCKET],
            ]]], 5, 2);
    }

    private function alive(string $socket): bool
    {
        if (!file_exists($socket)) {
            return false;
        }
        try {
            // 0 = keys listed, 1 = agent up with no keys, 2 = cannot connect.
            return \in_array($this->runner->run(['ssh-add', '-l'], null, ['SSH_AUTH_SOCK' => $socket], null, self::TIMEOUT)->exitCode, [0, 1], true);
        } catch (CommandRunnerException) {
            return false;
        }
    }

    private function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the ssh-agent directory.');
        }
        @chmod($dir, 0o700);
    }

    private function writePid(string $instanceId, string $pid): void
    {
        AtomicFile::write($this->paths->agentPidFile($instanceId), $pid."\n", 0o600);
    }
}
