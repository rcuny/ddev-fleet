<?php

declare(strict_types=1);

namespace App\Fleet\Host;

use App\Fleet\Projects\Rule\GitIdentity;

/**
 * Validation of the host's git bot identity (the default author name and e-mail for commits the fleet makes in
 * instances).
 *
 * The value rules are the project override's (`App\Fleet\Projects\Rule\GitIdentity`, FLE-37), so a host default and a
 * project override are held to one standard. This class adds only what is specific to the host default: both values
 * are optional, but together (a name without an e-mail, or the reverse, is refused). Values are trimmed; a blank one
 * counts as absent.
 */
final class GitIdentityRule
{
    public const int MAX_NAME_LENGTH = GitIdentity::NAME_MAX;
    public const int MAX_EMAIL_LENGTH = 254;

    private function __construct()
    {
    }

    /**
     * @return array{name: ?string, email: ?string} the trimmed values, blank as null
     */
    public static function normalise(?string $name, ?string $email): array
    {
        $name = null === $name ? '' : trim($name);
        $email = null === $email ? '' : trim($email);

        return ['name' => '' === $name ? null : $name, 'email' => '' === $email ? null : $email];
    }

    /**
     * @return array<'name'|'email', string> field => message; empty when the identity is acceptable
     */
    public static function violations(?string $name, ?string $email): array
    {
        ['name' => $name, 'email' => $email] = self::normalise($name, $email);
        $violations = [];

        if (null !== $name && null !== $message = GitIdentity::nameError($name)) {
            $violations['name'] = $message;
        }
        if (null !== $email && null !== $message = GitIdentity::emailError($email)) {
            $violations['email'] = $message;
        }

        if (null === $name xor null === $email) {
            $missing = null === $name ? 'name' : 'email';
            $violations[$missing] ??= 'Set both the name and the e-mail address, or leave both empty.';
        }

        return $violations;
    }
}
