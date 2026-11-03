<?php

declare(strict_types=1);

namespace App\Fleet\Projects\Rule;

/**
 * The value of `DRUPAL_ENV` a template writes into the instance's `.env` (decision P5, stricter than 0.x: the value is
 * written verbatim as `DRUPAL_ENV=<value>`, so it can carry neither a quote, a space nor a shell metacharacter).
 */
final class DrupalEnv
{
    private const string PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    public static function error(mixed $value): ?string
    {
        if (!\is_string($value) || 1 !== preg_match(self::PATTERN, $value)) {
            return 'Use 1 to 64 letters, digits, dots, underscores or dashes.';
        }

        return null;
    }
}
