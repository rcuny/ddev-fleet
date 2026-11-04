<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade\Check;

use App\Fleet\FleetPaths;
use App\Fleet\Shell\CommandRunner;
use App\Fleet\Upgrade\Release;
use App\Fleet\Upgrade\RepoUrl;
use Symfony\Component\Filesystem\Filesystem;

/** A depth-1 clone of the release tag into FLEET_HOME/staging, accepted only when HEAD is the release's commit. */
final readonly class ShallowCloneInstallerSource implements TargetInstallerSource
{
    public function __construct(private CommandRunner $runner, private RepoUrl $repo, private FleetPaths $paths)
    {
    }

    public function fetch(Release $release): ?string
    {
        $parent = $this->paths->stagingDir();
        $dir = $parent.'/upgrade-preflight-'.bin2hex(random_bytes(6));
        $fs = new Filesystem();
        try {
            $fs->mkdir($parent, 0o700);
            $clone = $this->runner->run(['git', 'clone', '--quiet', '--depth', '1', '--branch', $release->tag(), '--', $this->repo->value, $dir], env: ['GIT_TERMINAL_PROMPT' => '0'], timeout: 120.0);
            $head = $clone->isSuccess() ? $this->runner->run(['git', '-C', $dir, 'rev-parse', 'HEAD'], timeout: 10.0) : null;
        } catch (\Throwable) {
            $this->discard($dir);

            return null;
        }
        if (null === $head || !$head->isSuccess() || trim($head->stdout) !== $release->sha) {
            $this->discard($dir);

            return null;
        }

        return $dir;
    }

    public function discard(string $dir): void
    {
        if (str_starts_with($dir, $this->paths->stagingDir().'/upgrade-preflight-')) {
            try {
                new Filesystem()->remove($dir);
            } catch (\Throwable) {
            }
        }
    }
}
