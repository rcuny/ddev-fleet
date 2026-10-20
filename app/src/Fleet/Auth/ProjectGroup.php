<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Fleet\System\ConfigValue;

/**
 * The Authelia group that stands for membership of one project: `fleet-project-<name>`. AutheliaUsersFile puts it into
 * the groups of every member in users.yml (which Authelia reloads live), and the Caddy site of each of the project's
 * instances lets a request through only when Authelia's Remote-Groups header carries it (or the admin group).
 */
final class ProjectGroup
{
    public const string PREFIX = 'fleet-project-';

    public static function name(string $project): string
    {
        return self::PREFIX.ConfigValue::projectName($project);
    }
}
