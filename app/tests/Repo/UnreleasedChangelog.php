<?php

declare(strict_types=1);

namespace App\Tests\Repo;

/**
 * The unreleased changelog text: what is under `## [Unreleased]` in CHANGELOG.md plus every fragment in
 * changelog.d/, as the release step assembles them (ci/changelog-assemble.php).
 */
final class UnreleasedChangelog
{
    public static function text(string $root): string
    {
        require_once $root.'/ci/changelog-assemble.php';

        // @phpstan-ignore function.notFound (defined by the required script)
        return assembledBody($root);
    }
}
