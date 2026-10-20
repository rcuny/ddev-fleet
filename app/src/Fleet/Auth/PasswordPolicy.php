<?php

declare(strict_types=1);

namespace App\Fleet\Auth;

/**
 * What a fleet password must satisfy. Length is counted in characters (a passphrase in any script counts fairly);
 * the upper bound only exists so a hostile request cannot make argon2id hash megabytes.
 */
final readonly class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 128;

    /** Site-only users only unlock instance sites (to keep crawlers out), so a short password is allowed for them. */
    public const int SITE_ONLY_MIN_LENGTH = 4;

    /**
     * @return list<string> human readable reasons the password is refused; empty when it is acceptable. The
     *                      messages never contain the password.
     */
    public function violations(#[\SensitiveParameter] string $password, string $username, bool $siteOnly = false): array
    {
        if (!mb_check_encoding($password, 'UTF-8')) {
            return ['The password must be valid UTF-8.'];
        }

        $violations = [];
        $length = mb_strlen($password, 'UTF-8');
        $min = $siteOnly ? self::SITE_ONLY_MIN_LENGTH : self::MIN_LENGTH;
        if ($length < $min) {
            $violations[] = \sprintf('The password must be at least %d characters long.', $min);
        }
        if ($length > self::MAX_LENGTH) {
            $violations[] = \sprintf('The password must be at most %d characters long.', self::MAX_LENGTH);
        }
        if (str_contains($password, "\0")) {
            $violations[] = 'The password must not contain NUL characters.';
        }
        if (!$siteOnly && '' !== $username && 0 === strcasecmp($password, $username)) {
            $violations[] = 'The password must not be the username.';
        }

        return $violations;
    }
}
