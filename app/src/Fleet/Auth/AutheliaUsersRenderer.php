<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

use App\Domain\User\PasswordHash;
use App\Domain\User\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders Authelia's file user database (`users.yml`). The document is built as an array and serialised by the YAML
 * dumper, never by string templating, so no display name or email can change its structure. Every key and string is
 * double-quoted (so usernames such as `null`, `123` or `1e3` stay strings for Authelia's parser), keys are sorted and
 * the output is deterministic.
 */
final class AutheliaUsersRenderer
{
    /**
     * Authelia 4.39 refuses to start with an empty `users` map ("users: non zero value required", found on the first
     * real install). With no real user the file therefore holds this one entry: disabled, so it can never sign in,
     * and its password is the hash of 32 random bytes that were thrown away. It is not an account anyone created;
     * `install/etc/authelia-users-empty.yml` carries the same text for the installer (a test keeps the two equal).
     */
    public const string PLACEHOLDER_USERNAME = 'fleet-no-users-yet';
    public const string PLACEHOLDER_HASH = '$argon2id$v=19$m=65536,t=4,p=1$aXMuc1N4WFE2aEVYaXBEZQ$ZAstewOzdvBgoH5g+92NLoH0u3mlqP1dS8WCpjfjkII';

    // Empty lists are dumped as `[]` (a user without groups), the empty `users` map as `{}`: Authelia's parser needs
    // a sequence for groups and a map for users.
    private const int FLAGS = Yaml::DUMP_NUMERIC_KEY_AS_STRING | Yaml::DUMP_FORCE_DOUBLE_QUOTES_ON_VALUES
        | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP;

    /**
     * @param list<User>                  $users
     * @param array<string, list<string>> $projectsByUser username => names of the projects the user is a member of;
     *                                                    each becomes the project group (ProjectGroup) of that user
     *
     * @throws \InvalidArgumentException on a duplicate username or a hash that is not argon2id
     */
    public function render(array $users, array $projectsByUser = []): string
    {
        $entries = [];
        foreach ($users as $user) {
            $username = $user->username();
            if (isset($entries[$username])) {
                throw new \InvalidArgumentException(\sprintf('User "%s" is listed twice.', $username));
            }
            // The database is the source of truth, but a row that was edited by hand must not reach Authelia as is.
            PasswordHash::assertArgon2id($user->passwordHash());

            $entries[$username] = [
                'disabled' => !$user->isEnabled(),
                'displayname' => $user->displayName(),
                'password' => $user->passwordHash(),
                'email' => $user->email(),
                'groups' => self::groupsOf($user, $projectsByUser[$username] ?? []),
            ];
        }
        if ([] === $entries) {
            $entries[self::PLACEHOLDER_USERNAME] = [
                'disabled' => true,
                'displayname' => 'No users yet',
                'password' => self::PLACEHOLDER_HASH,
                'email' => 'no-users@invalid',
                'groups' => [],
            ];
        }
        ksort($entries, \SORT_STRING);

        return Yaml::dump(['users' => $entries], 4, 2, self::FLAGS);
    }

    /**
     * @param list<string> $projects
     *
     * @return list<string> the user's own groups, then the project groups, once each
     */
    private static function groupsOf(User $user, array $projects): array
    {
        $groups = $user->groups();
        foreach ($projects as $project) {
            $group = ProjectGroup::name($project);
            if (!\in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
