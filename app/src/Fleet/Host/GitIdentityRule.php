<?php

declare(strict_types=1);

namespace App\Fleet\Host;

/**
 * Validation of the git bot identity (the default author name and e-mail for commits the fleet makes in instances).
 *
 * RULING (FLE-51): the project-level rule of M0.03 (`App\Fleet\Projects\Rule`, a GitIdentity rule) did not exist on
 * this branch, so this is a small stand-alone version. Unify the two once M0.03 is merged (same limits, same
 * messages), so a host default and a project override are held to one standard.
 *
 * Both values are optional, but together: a name without an e-mail (or the reverse) is refused. Values are trimmed;
 * a blank one counts as absent.
 */
final class GitIdentityRule
{
    public const int MAX_NAME_LENGTH = 100;
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

        if (null !== $name) {
            if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $violations['name'] = \sprintf('The name must be valid text of at most %d characters.', self::MAX_NAME_LENGTH);
            } elseif (1 === preg_match('/[\x00-\x1F\x7F<>]/', $name)) {
                $violations['name'] = 'The name must not contain line breaks, control characters, "<" or ">".';
            }
        }

        if (null !== $email) {
            if (\strlen($email) > self::MAX_EMAIL_LENGTH) {
                $violations['email'] = \sprintf('The e-mail address must be at most %d characters.', self::MAX_EMAIL_LENGTH);
            } elseif (1 === preg_match('/[\s\x00-\x1F\x7F]/', $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $violations['email'] = 'That is not a valid e-mail address.';
            }
        }

        if (null === $name xor null === $email) {
            $missing = null === $name ? 'name' : 'email';
            $violations[$missing] ??= 'Set both the name and the e-mail address, or leave both empty.';
        }

        return $violations;
    }
}
