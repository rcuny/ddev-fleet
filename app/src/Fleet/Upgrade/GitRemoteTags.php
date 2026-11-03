<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\Shell\CommandTimedOut;

/**
 * `git ls-remote --tags <url> 'v1.*'` through the CommandRunner (argv, 15 s, no prompt). Annotated tags show up twice
 * (the tag object and the peeled commit `^{}`); a tag with no peeled line is lightweight and is ignored, because a
 * release is an annotated tag (the tag guard enforces it). The sha kept is the peeled commit.
 */
final readonly class GitRemoteTags
{
    public const float TIMEOUT_SECONDS = 15.0;

    public function __construct(private CommandRunner $runner, private RepoUrl $repo)
    {
    }

    /**
     * @return list<Release> all annotated v1.* tags (pre-releases included), newest first
     *
     * @throws UpgradeSourceUnavailable when the remote cannot be listed
     */
    public function fetch(): array
    {
        try {
            $result = $this->runner->run(
                ['git', 'ls-remote', '--tags', $this->repo->value, 'v1.*'],
                env: ['GIT_TERMINAL_PROMPT' => '0'],
                timeout: self::TIMEOUT_SECONDS,
            );
        } catch (CommandTimedOut) {
            throw new UpgradeSourceUnavailable(\sprintf('listing the releases timed out after %d s', (int) self::TIMEOUT_SECONDS));
        } catch (\Throwable $e) {
            throw new UpgradeSourceUnavailable('listing the releases failed: '.$e->getMessage(), 0, $e);
        }
        if (!$result->isSuccess()) {
            $why = trim(strtok($result->stderr, "\n") ?: '');
            throw new UpgradeSourceUnavailable(\sprintf('git ls-remote exited %d%s', $result->exitCode, '' === $why ? '' : ': '.$why));
        }

        return self::parse($result->stdout);
    }

    /**
     * @return list<Release>
     */
    public static function parse(string $output): array
    {
        $peeled = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (1 === preg_match('~^([0-9a-f]{40})\s+refs/tags/(v1\.[^\s^]+)\^\{\}$~D', $line, $m)) {
                $peeled[$m[2]] = $m[1];
            }
        }
        $releases = [];
        foreach ($peeled as $tag => $sha) {
            $version = Version::tryParse($tag);
            if (null !== $version && 1 === $version->major && $version->tag() === $tag) {
                $releases[] = new Release($version, $sha);
            }
        }
        usort($releases, static fn (Release $a, Release $b): int => $b->version->compare($a->version));

        return $releases;
    }
}
