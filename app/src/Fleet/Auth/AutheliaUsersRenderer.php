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
    // Empty lists are dumped as `[]` (a user without groups), the empty `users` map as `{}`: Authelia's parser needs
    // a sequence for groups and a map for users.
    private const int FLAGS = Yaml::DUMP_NUMERIC_KEY_AS_STRING | Yaml::DUMP_FORCE_DOUBLE_QUOTES_ON_VALUES
        | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP;

    /**
     * @param list<User> $users
     *
     * @throws \InvalidArgumentException on a duplicate username or a hash that is not argon2id
     */
    public function render(array $users): string
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
                'groups' => $user->groups(),
            ];
        }
        ksort($entries, \SORT_STRING);

        return Yaml::dump(['users' => [] === $entries ? new \stdClass() : $entries], 4, 2, self::FLAGS);
    }
}
