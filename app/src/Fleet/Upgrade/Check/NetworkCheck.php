<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Plan;
use App\Fleet\Upgrade\RepoUrl;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The installer clones the target from the release source, so the source must answer now (ruling R12).
 *
 * Findings: `network.source` (Block, not overridable): `git ls-remote` of the release source failed or timed out.
 * Remedy: restore the connection to the source, or point `--repo-url` at a mirror.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 800])]
final readonly class NetworkCheck implements UpgradeCheck
{
    public const string SOURCE = 'network.source';
    public const float TIMEOUT_SECONDS = 15.0;

    public function __construct(private CommandRunner $runner, private RepoUrl $repo)
    {
    }

    public function check(Plan $plan): array
    {
        try {
            $result = $this->runner->run(['git', 'ls-remote', '--exit-code', $this->repo->value, 'HEAD'], env: ['GIT_TERMINAL_PROMPT' => '0'], timeout: self::TIMEOUT_SECONDS);
            $reason = $result->isSuccess() ? null : \sprintf('git exited %d', $result->exitCode);
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        return null === $reason ? [] : [CheckResult::block(self::SOURCE, \sprintf('The release source %s is not reachable (%s).', $this->repo->value, $reason), 'Restore the connection to the source and run the check again.')];
    }
}
