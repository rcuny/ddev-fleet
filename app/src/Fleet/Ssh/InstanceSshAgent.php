<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Domain\Instance\Instance;
use App\Domain\Ssh\SshKeyRole;
use App\Domain\Template\SshIdentities;
use App\Domain\Template\TemplateRepository;
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
 *
 * FLE-167: the agent is a child of whichever process started it (the job worker, the status watcher). Those units run with
 * `KillMode=process`, so a restart of the unit (the worker restarts every hour by its own time limit, and on every
 * deploy) no longer takes the agents with it. The status watcher also calls start() for every running opted-in instance
 * every REFRESH_SECONDS, which revives an agent that died anyway and renews the key lifetime long before KEY_LIFETIME ends.
 */
final readonly class InstanceSshAgent
{
    /** Seconds a key stays loaded (`ssh-add -t`); every start and deploy refreshes it. */
    public const int KEY_LIFETIME = 86400;

    /** Where the socket directory appears in the container, and so what SSH_AUTH_SOCK points at. */
    public const string CONTAINER_DIR = '/run/fleet-ssh-agent';
    public const string CONTAINER_SOCKET = self::CONTAINER_DIR.'/agent.sock';

    public const string OVERRIDE_FILE = '.ddev/docker-compose.fleet-ssh.yaml';

    /** How often the status watcher calls start() for a running opted-in instance (revive + lifetime refresh). */
    public const int REFRESH_SECONDS = 600;

    private const float TIMEOUT = 20.0;

    public function __construct(
        private CommandRunner $runner,
        private FleetPaths $paths,
        private ExtraSshKey $extraKeys,
        private TemplateRepository $templates,
    ) {
    }

    /**
     * Whether the host has the key an agent project needs.
     */
    public function hasKey(): bool
    {
        return is_file($this->paths->sshPrivateKey(SshKeyRole::Push));
    }

    /**
     * Whether the host has every private key this instance's agent needs: the push key unless its template is
     * "extras only" (FLE-266). The extra keys are checked by start().
     */
    public function hasKeyFor(Instance $instance): bool
    {
        $template = $this->templates->find($instance->project(), $instance->templateName());

        return SshIdentities::ExtrasOnly === $template?->sshIdentities() || $this->hasKey();
    }

    /**
     * start() for an instance: the push key plus the extra keys its template names (FLE-190). The template is read fresh
     * from the repository on every call, so a start, a restart and the watcher's refresh all load what the template says now.
     *
     * @throws \RuntimeException as start()
     */
    public function startFor(Instance $instance): void
    {
        $template = $this->templates->find($instance->project(), $instance->templateName());
        $this->start($instance->id(), $template?->sshKeys() ?? [], SshIdentities::ExtrasOnly !== $template?->sshIdentities());
    }

    /**
     * Makes sure the instance's agent runs and holds the push key (unless $withPush is false: a template with "extras only",
     * FLE-266, never gets the write key). The socket directory exists afterwards (0700) even
     * when this throws, so a container that starts anyway never makes Docker create a root-owned directory there.
     *
     * @param list<string> $extraKeys names of the template's extra keys (ssh/<name>_ed25519), loaded after the push key with
     *                                the same lifetime; each is checked first (exists, 0600, owned by fleet), never the deploy key
     *
     * @throws \RuntimeException when the agent cannot be started or a key cannot be loaded
     */
    public function start(string $instanceId, array $extraKeys = [], bool $withPush = true): void
    {
        $dir = $this->paths->agentSocketDir($instanceId);
        $this->makeDir($this->paths->runDir());
        $this->makeDir($dir);

        foreach ($extraKeys as $name) {
            if (null !== $problem = $this->extraKeys->problem($name)) {
                throw new \RuntimeException($problem);
            }
        }

        $socket = $this->paths->agentSocket($instanceId);
        if (!$this->alive($socket)) {
            @unlink($socket);
            $started = $this->runner->run(['ssh-agent', '-a', $socket], null, [], null, self::TIMEOUT);
            if (!$started->isSuccess() || 1 !== preg_match('/SSH_AGENT_PID=(\d+)/', $started->stdout, $m)) {
                throw new \RuntimeException(\sprintf('ssh-agent did not start (exit %d).', $started->exitCode));
            }
            $this->writePid($instanceId, $m[1]);
        }

        if ($withPush) {
            $key = $this->paths->sshPrivateKey(SshKeyRole::Push);
            $added = $this->runner->run(['ssh-add', '-t', (string) self::KEY_LIFETIME, $key], null, ['SSH_AUTH_SOCK' => $socket], null, self::TIMEOUT);
            if (!$added->isSuccess()) {
                throw new \RuntimeException(\sprintf('The push key could not be loaded into the instance\'s ssh-agent (exit %d).', $added->exitCode));
            }
        } else {
            // A previous start may have loaded it (the template was switched to extras only): the agent must hold no write key now.
            $this->removePushKey($socket);
        }

        // The key path is the only argument: the key material never reaches argv, a log or the database.
        foreach ($extraKeys as $name) {
            $added = $this->runner->run(['ssh-add', '-t', (string) self::KEY_LIFETIME, $this->extraKeys->path($name)], null, ['SSH_AUTH_SOCK' => $socket], null, self::TIMEOUT);
            if (!$added->isSuccess()) {
                throw new \RuntimeException(\sprintf('The SSH key "%s" could not be loaded into the instance\'s ssh-agent (exit %d).', $name, $added->exitCode));
            }
        }
    }

    /**
     * `ssh-add -d` exits non-zero both when the key was not loaded (fine) and when the removal failed (not fine), so a
     * failure is checked against what the agent holds: the push key still listed means the write key is still there (FLE-270).
     *
     * @throws \RuntimeException when the push key is still in the agent
     */
    private function removePushKey(string $socket): void
    {
        $public = $this->paths->sshPublicKey(SshKeyRole::Push);
        $env = ['SSH_AUTH_SOCK' => $socket];
        $removed = $this->runner->run(['ssh-add', '-d', $public], null, $env, null, self::TIMEOUT);
        if ($removed->isSuccess()) {
            return;
        }

        $listed = $this->runner->run(['ssh-add', '-L'], null, $env, null, self::TIMEOUT);
        $line = is_file($public) ? (string) @file_get_contents($public) : '';
        $blob = explode(' ', trim($line))[1] ?? '';
        // exit 1 = the agent holds no keys at all; anything else than 0 and 1 means the agent cannot be asked.
        if (1 !== $listed->exitCode && (!$listed->isSuccess() || '' === $blob || str_contains($listed->stdout, $blob))) {
            throw new \RuntimeException(\sprintf('The push key could not be removed from the instance\'s ssh-agent (exit %d).', $removed->exitCode));
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
     * Opt-out cleanup (FLE-167): a project that no longer asks for `container_ssh` keeps neither an agent nor the compose
     * override, otherwise the next `ddev start` would mount a socket directory that no longer exists (Docker then creates
     * an empty root-owned one). Never throws; does nothing, and runs no command, when there is nothing to clean.
     *
     * @return bool whether an agent or an override was found
     */
    public function cleanUp(string $instanceId, string $checkoutDir): bool
    {
        $override = $checkoutDir.'/'.self::OVERRIDE_FILE;
        $found = is_file($override)
            || file_exists($this->paths->agentSocket($instanceId))
            || is_file($this->paths->agentPidFile($instanceId));

        $this->stop($instanceId);
        @unlink($override);

        return $found;
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

    /**
     * Whether this instance's agent answers on its socket (FLE-272). The status watcher uses it to keep re-keying the agent
     * of an instance whose state is momentarily "unknown", without ever starting an agent for one that has none.
     */
    public function isRunning(string $instanceId): bool
    {
        return $this->alive($this->paths->agentSocket($instanceId));
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
