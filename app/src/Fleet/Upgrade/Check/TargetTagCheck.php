<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Plan;
use App\Fleet\Upgrade\RepoUrl;
use App\Fleet\Upgrade\Version;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The target must be a release: an annotated tag (the catalog only lists those), of the 1.x series, not below 1.1.0
 * (ruling R14), and on `main` (the tag guard's rule, so a stray tag is never installed). "On main" is the tag's commit
 * equal to the tip of the source's `main`, or an ancestor of it when the checkout has the commits to prove it.
 *
 * Findings: `target.series` (Block, not overridable): not a v1 release. `target.below-minimum` (Block, not
 * overridable): older than 1.1.0. `target.not-on-main` (Block, not overridable): the commit is not on `main`.
 * `target.main-unverified` (Warn, overridable): `main` could not be read or the ancestry could not be proven.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 600])]
final readonly class TargetTagCheck implements UpgradeCheck
{
    public const string SERIES = 'target.series';
    public const string BELOW_MINIMUM = 'target.below-minimum';
    public const string NOT_ON_MAIN = 'target.not-on-main';
    public const string MAIN_UNVERIFIED = 'target.main-unverified';
    public const string MINIMUM = '1.1.0';

    public function __construct(private CommandRunner $runner, private RepoUrl $repo, private string $checkoutDir)
    {
    }

    public function check(Plan $plan): array
    {
        $target = $plan->target;
        if (null === $target) {
            return [];
        }
        $version = $target->version;
        if (1 !== $version->major) {
            return [CheckResult::block(self::SERIES, \sprintf('%s is not a 1.x release.', $target->tag()), 'Only the v1 series can be installed here; the v0 line is a separate product.')];
        }
        if ($version->compare(Version::parse(self::MINIMUM)) < 0) {
            return [CheckResult::block(self::BELOW_MINIMUM, \sprintf('%s is older than v%s, the first release that can be upgraded to or from.', $target->tag(), self::MINIMUM), 'Choose v'.self::MINIMUM.' or newer.')];
        }

        return $this->onMain($target->sha, $target->tag());
    }

    /**
     * @return list<CheckResult>
     */
    private function onMain(string $sha, string $tag): array
    {
        try {
            $tip = $this->runner->run(['git', 'ls-remote', $this->repo->value, 'refs/heads/main'], env: ['GIT_TERMINAL_PROMPT' => '0'], timeout: 15.0);
        } catch (\Throwable) {
            $tip = null;
        }
        $mainSha = null !== $tip && $tip->isSuccess() && 1 === preg_match('/^([0-9a-f]{40})\s/', $tip->stdout, $m) ? $m[1] : null;
        if (null === $mainSha) {
            return [CheckResult::warn(self::MAIN_UNVERIFIED, \sprintf('Could not read main from the release source, so %s was not checked to be on it.', $tag), 'Run the check again once the source answers.')];
        }
        if ($mainSha === $sha) {
            return [];
        }

        try {
            $ancestor = $this->runner->run(['git', '-C', $this->checkoutDir, 'merge-base', '--is-ancestor', $sha, $mainSha], timeout: 15.0);
        } catch (\Throwable) {
            $ancestor = null;
        }
        if (null !== $ancestor && $ancestor->isSuccess()) {
            return [];
        }
        if (null !== $ancestor && 1 === $ancestor->exitCode) {
            return [CheckResult::block(self::NOT_ON_MAIN, \sprintf('%s (%s) is not on main.', $tag, substr($sha, 0, 7)), 'Only tags cut from main are releases; choose another tag.')];
        }

        return [CheckResult::warn(self::MAIN_UNVERIFIED, \sprintf('%s is not main\'s tip and the checkout lacks the history to prove it is on main.', $tag), 'The installer re-verifies it; fetch the checkout first to settle this.')];
    }
}
