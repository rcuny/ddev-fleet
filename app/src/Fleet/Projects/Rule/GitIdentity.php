<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * The commit identity of a project's git bot override (decision P4). The consumer renders it into git configuration and
 * must encode it as data (YAML dump, argv), never concatenate it into a shell line: the name may hold quotes and `$`.
 */
final class GitIdentity
{
    public const int NAME_MAX = 100;

    private const string EMAIL = '/^(?=.{3,254}$)[A-Za-z0-9._%+-]{1,64}@(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D';

    public static function nameError(mixed $name): ?string
    {
        if (!\is_string($name) || '' === trim($name)) {
            return 'Enter a name.';
        }
        if (\strlen($name) > self::NAME_MAX * 4 || !mb_check_encoding($name, 'UTF-8')) {
            return 'The name contains characters that are not allowed.';
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            return \sprintf('The name must be at most %d characters.', self::NAME_MAX);
        }
        if (1 === preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}<>]/u', $name)) {
            return 'The name must not contain control characters, "<" or ">".';
        }

        return null;
    }

    public static function emailError(mixed $email): ?string
    {
        if (!\is_string($email) || 1 !== preg_match(self::EMAIL, $email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }

        return null;
    }
}
