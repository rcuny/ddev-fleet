<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Domain\User\User;

/**
 * A user as plain data, the JSON of the `fleet user:*` commands. Never carries the password hash.
 */
final class UserSnapshot
{
    /**
     * @param list<string> $projects the projects the user is a member of (an administrator reaches all of them without)
     *
     * @return array{username: string, display_name: string, email: string, admin: bool, site_only: bool, enabled: bool, projects: list<string>}
     */
    public static function of(User $user, bool $admin, array $projects): array
    {
        return [
            'username' => $user->username(),
            'display_name' => $user->displayName(),
            'email' => $user->email(),
            'admin' => $admin,
            'site_only' => $user->isSiteOnly(),
            'enabled' => $user->isEnabled(),
            'projects' => $projects,
        ];
    }
}
