<?php

declare(strict_types=1);

namespace App\Fleet\Git;

use App\Domain\Ssh\SshKeyRole;
use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\CommandRunner;

/**
 * The git calls the fleet makes, all through the CommandRunner. Branch and URL are hostile input: a value that could pass
 * as an option is refused before anything runs, `--` ends the options, and only https and ssh transports are allowed.
 */
final readonly class GitClient
{
    public const float CLONE_TIMEOUT = 1800.0;
    public const float HEAD_TIMEOUT = 10.0;

    public function __construct(private CommandRunner $runner, private FleetPaths $paths)
    {
    }

    /**
     * Clones $branch of $url into $dir (inside instances/), authenticated with the host's deploy key.
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
        if (!str_starts_with($dir, $this->paths->instancesDir().'/')) {
            throw new \InvalidArgumentException('The target directory is not inside the instances directory.');
        }

        $ssh = \sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%s',
            escapeshellarg($this->paths->sshPrivateKey(SshKeyRole::Deploy)),
            escapeshellarg($this->paths->knownHostsFile()),
        );

        return $this->runner->run(
            ['git', '-c', 'protocol.ext.allow=never', '-c', 'protocol.file.allow=never', 'clone', '--branch', $branch, '--', $url, $dir],
            $this->paths->instancesDir(),
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => 'https:ssh', 'GIT_SSH_COMMAND' => $ssh],
            $onOutput,
            self::CLONE_TIMEOUT,
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
}
