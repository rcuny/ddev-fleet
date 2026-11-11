<?php

declare(strict_types=1);

namespace App\Fleet\Ssh;

/**
 * Where the git access guide lives (FLE-296). Fleet serves no docs route, so the links point at the guide on `main` of the
 * public repository, the branch that carries the release that ships the guide.
 */
final class GuideLinks
{
    public const string GIT_ACCESS = 'https://github.com/rcuny/ddev-fleet/blob/main/docs/guides/10-git-access-and-ssh-keys.md';
    public const string TROUBLESHOOTING = self::GIT_ACCESS.'#troubleshooting';

    private function __construct()
    {
    }
}
