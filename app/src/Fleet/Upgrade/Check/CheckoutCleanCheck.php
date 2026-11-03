<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Plan;
use App\Fleet\Upgrade\RepoUrl;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The installer does `git reset --hard` on the checkout, which would silently discard local work (ruling R7), so a
 * checkout that is not exactly what the installer left is refused. None of these can be waived.
 *
 * Findings (all Block, not overridable):
 * - `checkout.missing`: the install directory is not a git checkout. Remedy: re-run the installer.
 * - `checkout.dirty`: `git status --porcelain` is not empty. Remedy: commit, stash or discard the local changes.
 * - `checkout.moved`: HEAD is not the commit in `installed-revision`. Remedy: check out that commit.
 * - `checkout.origin`: `origin` is not the release source. Remedy: `git remote set-url origin <source>`.
 * And `checkout.revision-unknown` (Warn): `installed-revision` is unreadable, so HEAD cannot be compared.
 */
#[AutoconfigureTag('fleet.upgrade_check', ['priority' => 700])]
final readonly class CheckoutCleanCheck implements UpgradeCheck
{
    public const string MISSING = 'checkout.missing';
    public const string DIRTY = 'checkout.dirty';
    public const string MOVED = 'checkout.moved';
    public const string ORIGIN = 'checkout.origin';
    public const string REVISION_UNKNOWN = 'checkout.revision-unknown';

    public function __construct(
        private CommandRunner $runner,
        private RepoUrl $repo,
        private string $checkoutDir,
        private string $revisionFile,
    ) {
    }

    public function check(Plan $plan): array
    {
        if (!is_dir($this->checkoutDir.'/.git')) {
            return [CheckResult::block(self::MISSING, \sprintf('%s is not a git checkout.', $this->checkoutDir), 'Re-run the installer to restore the checkout.')];
        }

        $results = [];
        $status = $this->git(['status', '--porcelain']);
        if (null === $status || '' !== trim($status)) {
            $results[] = CheckResult::block(self::DIRTY, null === $status ? 'git status failed in the checkout.' : 'The checkout has local changes: '.self::firstLines($status), 'Commit, stash or discard the local changes in '.$this->checkoutDir.'; the installer would erase them.');
        }

        $expected = is_readable($this->revisionFile) ? trim((string) file_get_contents($this->revisionFile)) : '';
        $head = $this->git(['rev-parse', 'HEAD']);
        if (1 !== preg_match('/^[0-9a-f]{40}$/D', $expected)) {
            $results[] = CheckResult::warn(self::REVISION_UNKNOWN, 'The installed revision is unreadable, so the checkout cannot be compared with it.', 'Check that '.$this->revisionFile.' holds a commit sha.', false);
        } elseif (null === $head || trim($head) !== $expected) {
            $results[] = CheckResult::block(self::MOVED, \sprintf('The checkout is at %s, not at the installed revision %s.', null === $head ? 'an unknown commit' : substr(trim($head), 0, 7), substr($expected, 0, 7)), 'Run `git -C '.$this->checkoutDir.' checkout '.substr($expected, 0, 7).'` to put it back.');
        }

        $origin = $this->git(['remote', 'get-url', 'origin']);
        if (null === $origin || self::normalise($origin) !== self::normalise($this->repo->value)) {
            $results[] = CheckResult::block(self::ORIGIN, \sprintf('origin is %s, not the release source %s.', null === $origin ? 'missing' : trim($origin), $this->repo->value), 'Run `git -C '.$this->checkoutDir.' remote set-url origin '.$this->repo->value.'`.');
        }

        return $results;
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args): ?string
    {
        try {
            $result = $this->runner->run(['git', '-C', $this->checkoutDir, ...$args], env: ['GIT_TERMINAL_PROMPT' => '0'], timeout: 20.0);
        } catch (\Throwable) {
            return null;
        }

        return $result->isSuccess() ? $result->stdout : null;
    }

    private static function normalise(string $url): string
    {
        return strtolower(preg_replace('~(\.git)?/*$~', '', trim($url)) ?? '');
    }

    private static function firstLines(string $porcelain): string
    {
        $lines = \array_slice(array_filter(explode("\n", $porcelain), static fn (string $l): bool => '' !== trim($l)), 0, 3);

        return implode('; ', array_map('trim', $lines));
    }
}
