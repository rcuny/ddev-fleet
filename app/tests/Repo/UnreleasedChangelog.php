<?php

declare(strict_types=1);

namespace App\Tests\Repo;

/**
 * The 1.x changelog text the docs tests search: what is under `## [Unreleased]` in CHANGELOG.md plus every
 * fragment in changelog.d/ (as the release step assembles them, ci/changelog-assemble.php), followed by the
 * released `## [1.x.y]` sections. A release moves the fragments into a version section, so an entry stays
 * findable after `php ci/changelog-assemble.php X.Y.Z`.
 */
final class UnreleasedChangelog
{
    public static function text(string $root): string
    {
        require_once $root.'/ci/changelog-assemble.php';

        // @phpstan-ignore function.notFound (defined by the required script)
        $pending = assembledBody($root);

        return $pending."\n".self::released($root);
    }

    /**
     * The released 1.x sections of CHANGELOG.md, newest first.
     */
    private static function released(string $root): string
    {
        $changelog = (string) file_get_contents($root.'/CHANGELOG.md');
        if (1 !== preg_match('/^## \[1\.\d+\.\d+\].*?(?=^## \[0\.|\z)/ms', $changelog, $match)) {
            return '';
        }

        return $match[0];
    }
}
