<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandRunnerException;
use App\Fleet\Shell\CommandTimedOut;

/**
 * Asks a git host whether the host's push key itself is registered (FLE-271). The test offers exactly that key:
 * `-F /dev/null` ignores the running user's ~/.ssh/config (which may pin another key, such as the deploy key, to the
 * host), `IdentitiesOnly` and `IdentityAgent=none` keep an agent's keys out, and the host key is checked against Fleet's
 * pinned known hosts. So a pass means the push key works, not some other identity.
 */
final readonly class PushKeyCheck
{
    public const string DEFAULT_HOST = 'bitbucket.org';
    private const float TIMEOUT = 20.0;

    public function __construct(private FleetPaths $paths, private CommandRunner $runner)
    {
    }

    /**
     * @throws \InvalidArgumentException when $host is not a plain SSH host name
     */
    public function run(string $host = self::DEFAULT_HOST): PushKeyState
    {
        $host = KnownHostEntry::normalizeHost($host);
        if (str_starts_with($host, '[')) {
            throw new \InvalidArgumentException('Give the host name without a port.');
        }
        $key = $this->paths->sshPrivateKey(SshKeyRole::Push);
        if (!is_file($key)) {
            return PushKeyState::NoPushKey;
        }

        try {
            $result = $this->runner->run(self::argv($key, $this->paths->knownHostsFile(), $host), null, ['GIT_TERMINAL_PROMPT' => '0'], null, self::TIMEOUT);
        } catch (CommandTimedOut|CommandRunnerException) {
            return PushKeyState::Unreachable;
        }

        return self::classify($result->exitCode, $result->stdout."\n".$result->stderr);
    }

    /**
     * @return list<string>
     */
    public static function argv(string $key, string $knownHosts, string $host): array
    {
        return [
            'ssh', '-F', '/dev/null',
            '-i', $key,
            '-o', 'IdentitiesOnly=yes',
            '-o', 'IdentityAgent=none',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.$knownHosts,
            '-o', 'ConnectTimeout=10',
            '-T', 'git@'.$host,
        ];
    }

    public static function classify(int $exitCode, string $output): PushKeyState
    {
        if (1 === preg_match('/permission denied/i', $output)) {
            return PushKeyState::NotRegistered;
        }
        if (1 === preg_match('/host key verification failed|no .* host key is known/i', $output)) {
            return PushKeyState::HostNotTrusted;
        }
        // Bitbucket exits 0 ("authenticated via ssh key"), GitHub 1 ("successfully authenticated"), GitLab 0 ("Welcome to GitLab").
        if (0 === $exitCode || 1 === preg_match('/successfully authenticated|authenticated via|logged in as|welcome to gitlab/i', $output)) {
            return PushKeyState::Registered;
        }

        return PushKeyState::Unreachable;
    }
}
